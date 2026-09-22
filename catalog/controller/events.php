<?php
namespace Opencart\Catalog\Controller\Extension\AbandonedCart;

require_once DIR_EXTENSION . 'abandoned_cart/system/library/cc_abandoned_cart/settings.php';
require_once DIR_EXTENSION . 'abandoned_cart/system/library/cc_abandoned_cart/license.php';
require_once DIR_EXTENSION . 'abandoned_cart/system/library/cc_abandoned_cart/repository.php';
require_once DIR_EXTENSION . 'abandoned_cart/system/library/cc_abandoned_cart/capture.php';
require_once DIR_EXTENSION . 'abandoned_cart/system/library/cc_abandoned_cart/coupons.php';
require_once DIR_EXTENSION . 'abandoned_cart/system/library/cc_abandoned_cart/turbosms.php';

use Opencart\System\Library\CcAbandonedCart\Settings;
use Opencart\System\Library\CcAbandonedCart\Repository;
use Opencart\System\Library\CcAbandonedCart\Capture;
use Opencart\System\Library\CcAbandonedCart\Coupons;
use Opencart\System\Library\CcAbandonedCart\TurboSms;

/**
 * Storefront event handlers.
 *
 * Every handler bails out immediately when the extension is switched off, so
 * the cost on an ordinary page view is a single already-loaded config lookup.
 */
class Events extends \Opencart\System\Engine\Controller {

	private function enabled(): bool {
		return (string)$this->config->get(Settings::PREFIX . 'status') === '1';
	}

	private function capture(): Capture {
		$settings = new Settings($this->config);

		return new Capture($this->registry, $settings, new Repository($this->db));
	}

	/**
	 * Refresh the snapshot at most once a minute. Browsing must not write a row
	 * on every page view; real cart changes still sync immediately through the
	 * cart.* controller events and through the checkout capture endpoint.
	 */
	private function syncThrottled(int $seconds = 60): void {
		$last = (int)($this->session->data['abandoned_cart_touched'] ?? 0);
		if ($last > 0 && (time() - $last) < $seconds) {
			return;
		}

		// Claim the slot before doing the work so a failure cannot busy-loop.
		$this->session->data['abandoned_cart_touched'] = time();

		try {
			$this->capture()->store();
		} catch (\Throwable $e) {
			// Never let bookkeeping break the storefront.
		}
	}

	/**
	 * catalog/controller/checkout/cart.add|edit|remove /after
	 *
	 * Any cart mutation refreshes the stored snapshot and the idle clock.
	 */
	public function cartChanged(string &$route, array &$args, &$output): void {
		if (!$this->enabled()) {
			return;
		}

		try {
			$this->capture()->store();
		} catch (\Throwable $e) {
			// Never let bookkeeping break the storefront.
		}
	}

	/**
	 * catalog/controller/checkout/register.save/after
	 *
	 * The moment a guest hands over an address the cart becomes recoverable.
	 */
	public function registerSaved(string &$route, array &$args, &$output): void {
		if (!$this->enabled()) {
			return;
		}

		$email = trim((string)($this->request->post['email'] ?? ''));
		$phone = trim((string)($this->request->post['telephone'] ?? ''));
		$name  = trim((string)($this->request->post['firstname'] ?? '') . ' ' . (string)($this->request->post['lastname'] ?? ''));

		try {
			$capture = $this->capture();
			if ($phone !== '') {
				$capture->rememberPhone($phone, $name);
			}
			if ($email !== '') {
				$capture->rememberEmail($email, $name);
			} elseif ($phone === '') {
				$capture->store();
			}
		} catch (\Throwable $e) {
			// Ignore.
		}
	}

	/**
	 * catalog/model/checkout/order.addHistory/after
	 *
	 * args: [order_id, order_status_id, comment, notify]
	 *
	 * An order from the same address (or customer id, or phone) marks one
	 * abandoned cart of that shopper as "recovered" and closes the rest.
	 */
	public function orderHistoryAdded(string &$route, array &$args, &$output): void {
		if (!$this->enabled()) {
			return;
		}

		$orderId = (int)($args[0] ?? 0);
		if ($orderId < 1) {
			return;
		}

		try {
			$this->load->model('checkout/order');
			$order = $this->model_checkout_order->getOrder($orderId);
			if (!$order) {
				return;
			}

			$email      = trim((string)($order['email'] ?? ''));
			$customerId = (int)($order['customer_id'] ?? 0);
			$phone      = TurboSms::normalisePhone((string)($order['telephone'] ?? ''));
			if ($email === '' && $customerId < 1 && $phone === '') {
				return;
			}

			// Only the first status of an order decides. A later change (the
			// payment callback, the manager moving it to "Complete" days after)
			// must not touch the carts the same buyer has opened since.
			$history = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "order_history` WHERE `order_id` = " . $orderId)->row;
			if ((int)($history['total'] ?? 0) > 1) {
				return;
			}

			$repository = new Repository($this->db);

			// order.total is the gross order value (product price + tax +
			// shipping), so it is the figure to report as recovered revenue.
			$recoveredTotal = round((float)($order['total'] ?? 0) * (float)($order['currency_value'] ?: 1), 4);

			$capture = $this->capture();
			$rows    = $repository->openForCustomer($email, $customerId, $phone);

			// ⚠ One order recovers ONE cart. Closing every open row of the buyer
			// as "recovered" counted the same purchase once per row (a shopper
			// with an older cart, or the row a fresh session created, doubled
			// the figures), and a plain purchase that was never abandoned was
			// reported as recovered revenue. The credited row is the one of this
			// session if it was really abandoned, otherwise the latest one that
			// was.
			$credit = $this->pickRecovered($rows, $capture->sessionKey());

			foreach ($rows as $row) {
				$id = (int)$row['abandoned_cart_id'];

				if ($id === $credit) {
					$repository->update($id, [
						'status'             => Repository::STATUS_RECOVERED,
						'recovered_order_id' => $orderId,
						'recovered_total'    => $recoveredTotal,
						'recovered_at'       => true,
						'token_hash'         => '',
						'msg_token_hash'     => '',
						'token_expires_at'   => null,
					]);
				} elseif (!Repository::wasAbandoned($row)) {
					// A live cart that simply turned into this order.
					$repository->delete($id);
				} else {
					// An older abandoned cart of a buyer who has just ordered:
					// stop reminding, do not count it as recovered.
					$repository->update($id, [
						'status'           => Repository::STATUS_LOST,
						'token_hash'       => '',
						'msg_token_hash'   => '',
						'token_expires_at' => null,
					]);
				}
			}

			$capture->forgetEmail();
		} catch (\Throwable $e) {
			// Ignore.
		}
	}

	/**
	 * Which of the buyer's open rows an order recovers: 0 when none of them was
	 * ever abandoned (an ordinary purchase).
	 */
	private function pickRecovered(array $rows, string $sessionKey): int {
		$best  = 0;
		$score = null;

		foreach ($rows as $row) {
			if (!Repository::wasAbandoned($row)) {
				continue;
			}

			$candidate = [
				($sessionKey !== '' && (string)$row['session_key'] === $sessionKey) ? 1 : 0,
				(string)$row['updated_at'],
				(int)$row['abandoned_cart_id'],
			];

			if ($score === null || $candidate > $score) {
				$score = $candidate;
				$best  = (int)$row['abandoned_cart_id'];
			}
		}

		return $best;
	}

	/**
	 * catalog/model/marketing/coupon.getCoupon/after
	 *
	 * Personal recovery coupons are bound to the shopper they were issued for.
	 * OpenCart has no native e-mail restriction, so the binding is enforced
	 * here: a coupon generated by this extension is only handed back when the
	 * current session matches the address it belongs to.
	 */
	public function couponGuard(string &$route, array &$args, &$output): void {
		if (!$this->enabled() || empty($output) || !is_array($output)) {
			return;
		}

		$code = trim((string)($args[0] ?? ''));
		if ($code === '') {
			return;
		}

		try {
			$settings   = new Settings($this->config);
			$repository = new Repository($this->db);
			$coupons    = new Coupons($this->db, $settings, $repository);
			$capture    = new Capture($this->registry, $settings, $repository);

			$customerId = $this->customer->isLogged() ? (int)$this->customer->getId() : 0;

			if (!$coupons->isAllowedFor($code, $capture->currentEmail(), $customerId)) {
				$output = [];
			}
		} catch (\Throwable $e) {
			// Ignore.
		}
	}

	/**
	 * catalog/view/common/footer/after
	 *
	 * The footer is rendered through the loader on every storefront page, which
	 * makes it the one hook guaranteed to fire regardless of how the request
	 * was routed. It does two things:
	 *
	 *  1. refreshes the stored cart snapshot (throttled, so ordinary browsing
	 *     costs one cheap session check) — this is the capture path that does
	 *     not depend on controller events firing;
	 *  2. injects the small checkout script that posts the e-mail a guest types
	 *     so the cart becomes recoverable before the order is placed.
	 */
	public function injectCapture(string &$route, &$args, &$output): void {
		if (!$this->enabled() || !is_string($output)) {
			return;
		}

		$this->syncThrottled();

		$current = (string)($this->request->get['route'] ?? '');
		if (strpos($current, 'checkout/') !== 0) {
			return;
		}

		// Nothing to capture for a shopper we already know.
		if ($this->customer->isLogged()) {
			return;
		}

		$endpoint = htmlspecialchars(
			(defined('HTTP_SERVER') ? HTTP_SERVER : '') . 'index.php?route=extension/abandoned_cart/abandoned_cart.capture',
			ENT_QUOTES,
			'UTF-8'
		);

		// The phone is posted too: plenty of Ukrainian checkouts make it the
		// required field and the e-mail optional, and a phone alone is enough
		// for the Pro Viber/SMS reminder.
		$output .= '<script>(function(){'
			. 'var sent={};'
			. 'function push(k,v){'
			. 'if(!v||sent[k]===v){return;}'
			. 'if(k==="email"&&!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v)){return;}'
			. 'if(k==="telephone"&&v.replace(/\D/g,"").length<9){return;}'
			. 'sent[k]=v;'
			. 'var b=new FormData();b.append(k,v);'
			. 'var f=document.querySelector(\'input[name="firstname"]\');'
			. 'var l=document.querySelector(\'input[name="lastname"]\');'
			. 'if(f){b.append("firstname",f.value||"");}'
			. 'if(l){b.append("lastname",l.value||"");}'
			. 'fetch("' . $endpoint . '",{method:"POST",body:b,credentials:"same-origin"}).catch(function(){});'
			. '}'
			. 'document.addEventListener("change",function(e){'
			. 'var t=e.target;'
			. 'if(t&&(t.name==="email"||t.name==="telephone")){push(t.name,(t.value||"").trim());}'
			. '},true);'
			. '})();</script>';
	}
}

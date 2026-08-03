<?php
namespace Opencart\Catalog\Controller\Extension\AbandonedCart;

require_once DIR_EXTENSION . 'abandoned_cart/system/library/cc_abandoned_cart/settings.php';
require_once DIR_EXTENSION . 'abandoned_cart/system/library/cc_abandoned_cart/license.php';
require_once DIR_EXTENSION . 'abandoned_cart/system/library/cc_abandoned_cart/repository.php';
require_once DIR_EXTENSION . 'abandoned_cart/system/library/cc_abandoned_cart/mailer.php';
require_once DIR_EXTENSION . 'abandoned_cart/system/library/cc_abandoned_cart/coupons.php';
require_once DIR_EXTENSION . 'abandoned_cart/system/library/cc_abandoned_cart/telegram.php';

use Opencart\System\Library\CcAbandonedCart\Settings;
use Opencart\System\Library\CcAbandonedCart\License;
use Opencart\System\Library\CcAbandonedCart\Repository;
use Opencart\System\Library\CcAbandonedCart\Mailer;
use Opencart\System\Library\CcAbandonedCart\Coupons;
use Opencart\System\Library\CcAbandonedCart\Telegram;

/**
 * Scheduled work.
 *
 * Registered with OpenCart's own cron table (hourly scan, daily cleanup). For
 * finer granularity point a real system cron at
 * index.php?route=extension/abandoned_cart/cron.scan
 */
class Cron extends \Opencart\System\Engine\Controller {

	/** Promote idle carts, then send whatever reminder is due. */
	public function scan(): void {
		if (!$this->enabled()) {
			return;
		}

		$this->load->language('extension/abandoned_cart/abandoned_cart');

		$settings   = new Settings($this->config);
		$repository = new Repository($this->db);
		$isPro      = License::isPro($this->registry);

		$this->promoteIdle($settings, $repository, $isPro);
		$this->sendDue($settings, $repository, $isPro);
	}

	/** Close carts past their recovery window, then honour retention. */
	public function cleanup(): void {
		if (!$this->enabled()) {
			return;
		}

		$settings   = new Settings($this->config);
		$repository = new Repository($this->db);

		$repository->expireStale(max(1, $settings->getInt('token_lifetime', 7)));

		$retention = $settings->getInt('retention_days', 90);
		if ($retention > 0) {
			$repository->purgeOld($retention);
		}

		try {
			(new Coupons($this->db, $settings, $repository))->purgeOrphans();
		} catch (\Throwable $e) {
			// Ignore.
		}
	}

	private function enabled(): bool {
		return (string)$this->config->get(Settings::PREFIX . 'status') === '1';
	}

	private function promoteIdle(Settings $settings, Repository $repository, bool $isPro): void {
		$minutes  = max(1, $settings->getInt('abandon_after', 60));
		$telegram = new Telegram($settings);
		$notify   = $telegram->isEnabled($isPro);

		foreach ($repository->staleActive($minutes) as $row) {
			$repository->update((int)$row['abandoned_cart_id'], [
				'status'       => Repository::STATUS_ABANDONED,
				'abandoned_at' => true,
			]);

			if (!$notify) {
				continue;
			}

			try {
				$telegram->notifyAbandoned($row, [
					'title'    => $this->language->get('text_tg_title'),
					'email'    => $this->language->get('text_tg_email'),
					'total'    => $this->language->get('text_tg_total'),
					'customer' => $this->language->get('text_tg_customer'),
				]);
			} catch (\Throwable $e) {
				// A failing notification must not stop the queue.
			}
		}
	}

	private function sendDue(Settings $settings, Repository $repository, bool $isPro): void {
		$steps = $settings->emailSteps($isPro);
		if (!$steps) {
			return;
		}

		$byStep = [];
		foreach ($steps as $step) {
			// An empty template falls back to the shop-language default.
			if (trim((string)$step['subject']) === '') {
				$step['subject'] = (string)$this->language->get('text_email_' . $step['step'] . '_subject');
			}
			if (trim((string)$step['body']) === '') {
				$step['body'] = (string)$this->language->get('text_email_' . $step['step'] . '_body');
			}
			$byStep[(int)$step['step']] = $step;
		}

		$maxStep  = max(array_keys($byStep));
		$cooldown = $settings->getInt('email_cooldown', 3);

		$mailer  = new Mailer($this->registry, $settings, $repository);
		$coupons = new Coupons($this->db, $settings, $repository);

		$strings = [
			'fallback_name' => (string)$this->language->get('text_customer_fallback'),
			'item_line'     => (string)$this->language->get('text_item_line'),
		];
		$couponTemplate = (string)$this->language->get('text_coupon_line');
		$currency       = (string)$this->config->get('config_currency');

		foreach ($repository->pendingReminders($maxStep) as $row) {
			$next = (int)$row['emails_sent'] + 1;
			if (!isset($byStep[$next])) {
				continue;
			}
			$step = $byStep[$next];

			// Step 1 is measured from abandonment, later steps from the last send.
			$anchor = ($next === 1) ? (string)($row['abandoned_at'] ?? '') : (string)($row['last_email_at'] ?? '');
			if ($anchor === '') {
				continue;
			}

			$dueAt = strtotime($anchor) + ((int)$step['delay'] * 60);
			if ($dueAt > time()) {
				continue;
			}

			// Throttle: do not pester an address another cart mailed recently.
			if ($next === 1 && $repository->emailedRecently((string)$row['email'], $cooldown, (int)$row['abandoned_cart_id'])) {
				continue;
			}

			try {
				$code = $coupons->codeForCart($row, $next, $isPro);
				if ($code !== '') {
					// Re-read so the freshly written coupon code is on the row.
					$row['coupon_code'] = $code;
				}
				$text = $coupons->describe($code, $couponTemplate, $currency);

				$mailer->send($row, $step, $strings, $code, $text);
			} catch (\Throwable $e) {
				// Move on to the next cart.
			}
		}
	}
}

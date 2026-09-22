<?php
namespace Opencart\Catalog\Controller\Extension\AbandonedCart;

require_once DIR_EXTENSION . 'abandoned_cart/system/library/cc_abandoned_cart/settings.php';
require_once DIR_EXTENSION . 'abandoned_cart/system/library/cc_abandoned_cart/repository.php';
require_once DIR_EXTENSION . 'abandoned_cart/system/library/cc_abandoned_cart/capture.php';
require_once DIR_EXTENSION . 'abandoned_cart/system/library/cc_abandoned_cart/telegram.php';

use Opencart\System\Library\CcAbandonedCart\Settings;
use Opencart\System\Library\CcAbandonedCart\Repository;
use Opencart\System\Library\CcAbandonedCart\Capture;
use Opencart\System\Library\CcAbandonedCart\Telegram;

/**
 * Storefront endpoints: the recovery link and the guest e-mail capture.
 */
class AbandonedCart extends \Opencart\System\Engine\Controller {

	/**
	 * index.php?route=extension/abandoned_cart/abandoned_cart.recover&token=…
	 *
	 * The token is the credential: only its SHA-256 hash is stored, the lookup
	 * is a constant-time comparison, and the token is burned the moment it is
	 * used.
	 *
	 * ⚠ Two steps on purpose. The link is opened from a mail client or webmail,
	 * i.e. from ANOTHER site, and OpenCart 4 issues OCSESSID with
	 * SameSite=Strict: the browser does not send it on that request nor on any
	 * redirect in the same chain. Restoring the cart there and answering 302
	 * put the items into a session the next page never sees — "cart is empty"
	 * and the one-time token already burned. So the GET only answers a tiny
	 * page that re-submits the token by POST from our own origin; that request
	 * carries the session cookie and does the actual work. Link scanners of
	 * mail providers that merely fetch the URL no longer burn the token either.
	 */
	public function recover(): void {
		$this->load->language('extension/abandoned_cart/abandoned_cart');

		$cartUrl = $this->url->link('checkout/cart', 'language=' . $this->config->get('config_language'));

		if ((string)$this->config->get(Settings::PREFIX . 'status') !== '1') {
			$this->response->redirect($cartUrl);

			return;
		}

		$isPost = ($this->request->server['REQUEST_METHOD'] ?? 'GET') === 'POST';
		$token  = trim((string)($isPost ? ($this->request->post['token'] ?? '') : ($this->request->get['token'] ?? '')));
		if ($token === '' || !preg_match('/^[a-f0-9]{16,64}$/', $token)) {
			$this->response->redirect($cartUrl);

			return;
		}

		if (!$isPost) {
			$this->bouncePage($token);

			return;
		}

		$repository = new Repository($this->db);
		$hash       = hash('sha256', $token);
		$row        = $repository->findByTokenHash($hash);

		$matches = $row && (hash_equals((string)$row['token_hash'], $hash) || hash_equals((string)($row['msg_token_hash'] ?? ''), $hash));

		if (!$matches) {
			$this->session->data['error'] = $this->language->get('error_link_invalid');
			$this->response->redirect($cartUrl);

			return;
		}

		$expires = (string)($row['token_expires_at'] ?? '');
		if ($expires === '' || strtotime($expires) < time()) {
			$repository->clearToken((int)$row['abandoned_cart_id']);
			$this->session->data['error'] = $this->language->get('error_link_expired');
			$this->response->redirect($cartUrl);

			return;
		}

		$restored = $this->restoreCart($row);

		// One-time token: burn it whether or not every line survived.
		$repository->clearToken((int)$row['abandoned_cart_id']);

		if ($restored > 0) {
			$this->adoptIdentity($row);
			$this->attachToSession($repository, $row);
			$this->session->data['success'] = $this->language->get('text_cart_restored');
		} else {
			$this->session->data['error'] = $this->language->get('error_products_gone');
		}

		$this->response->redirect($cartUrl);
	}

	/**
	 * The intermediate page of the recovery link: posts the token back to
	 * recover() from our own origin, so the session cookie travels with it.
	 * A visible button covers browsers with scripts switched off.
	 */
	private function bouncePage(string $token): void {
		// Relative on purpose: the form must post to exactly the origin (scheme
		// included) the link was opened on.
		$action = 'index.php?route=extension/abandoned_cart/abandoned_cart.recover';
		$title  = htmlspecialchars((string)$this->language->get('text_recover_wait'), ENT_QUOTES, 'UTF-8');
		$button = htmlspecialchars((string)$this->language->get('button_recover'), ENT_QUOTES, 'UTF-8');
		$lang   = htmlspecialchars((string)$this->language->get('code'), ENT_QUOTES, 'UTF-8');

		$this->response->addHeader('Content-Type: text/html; charset=utf-8');
		$this->response->addHeader('X-Robots-Tag: noindex, nofollow');
		$this->response->addHeader('Referrer-Policy: no-referrer');
		$this->response->setOutput('<!DOCTYPE html><html lang="' . $lang . '"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
			. '<title>' . $title . '</title></head>'
			. '<body style="font-family:Arial,Helvetica,sans-serif;text-align:center;padding:48px 16px;color:#23282d">'
			. '<form id="cc-ac-recover" method="post" action="' . $action . '">'
			. '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
			. '<p>' . $title . '</p>'
			. '<button type="submit" style="padding:10px 22px;font-size:15px;cursor:pointer">' . $button . '</button>'
			. '</form>'
			. '<script>document.getElementById("cc-ac-recover").submit();</script>'
			. '</body></html>');
	}

	/**
	 * Make the recovered row THE row of the current session.
	 *
	 * The shopper comes back with a new session, so the next cart snapshot
	 * would be keyed differently and a second row appeared next to the
	 * recovered one; the order then closed both and the statistics counted the
	 * same purchase twice. Re-keying the original row keeps one cart, one row.
	 */
	private function attachToSession(Repository $repository, array $row): void {
		try {
			$key = (new Capture($this->registry, new Settings($this->config), $repository))->sessionKey();
			$id  = (int)$row['abandoned_cart_id'];

			if ($key === '' || $key === (string)$row['session_key']) {
				return;
			}

			$other = $repository->findBySession($key);
			if ($other && (int)$other['abandoned_cart_id'] !== $id) {
				if ((string)$other['status'] === Repository::STATUS_ACTIVE && !Repository::wasAbandoned($other)) {
					// The live cart was just replaced by the recovered one.
					$repository->delete((int)$other['abandoned_cart_id']);
				} else {
					// History stays, it only stops answering to this session.
					$repository->rekey((int)$other['abandoned_cart_id'], 'detached-' . (int)$other['abandoned_cart_id']);
				}
			}

			$repository->rekey($id, $key);
		} catch (\Throwable $e) {
			// Never let bookkeeping break the recovery itself.
		}
	}

	/**
	 * index.php?route=extension/abandoned_cart/abandoned_cart.capture
	 *
	 * POST endpoint used by the checkout capture script: it records the e-mail
	 * a guest has typed so the cart becomes recoverable before the order is
	 * placed. Stores nothing else and answers a bare JSON acknowledgement.
	 */
	public function capture(): void {
		$json = ['ok' => false];

		if ((string)$this->config->get(Settings::PREFIX . 'status') === '1') {
			$email = trim((string)($this->request->post['email'] ?? ''));
			$phone = trim((string)($this->request->post['telephone'] ?? ''));
			$name  = trim((string)($this->request->post['firstname'] ?? '') . ' ' . (string)($this->request->post['lastname'] ?? ''));

			try {
				$settings = new Settings($this->config);
				$capture  = new Capture($this->registry, $settings, new Repository($this->db));

				if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
					$capture->rememberEmail($email, $name);
					$json['ok'] = true;
				}
				if ($phone !== '') {
					$capture->rememberPhone($phone, $name);
					$json['ok'] = true;
				}
			} catch (\Throwable $e) {
				$json['ok'] = false;
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput((string)json_encode($json));
	}

	/**
	 * index.php?route=extension/abandoned_cart/abandoned_cart.telegram
	 *
	 * Telegram webhook. The shop owner writes /start to the bot and the chat id
	 * lands in the settings by itself, which beats hunting for it by hand.
	 *
	 * The route is public — anybody may POST here — so the ONLY thing that makes
	 * it act is the X-Telegram-Bot-Api-Secret-Token header matching the secret we
	 * handed to setWebhook(). Telegram sends that header on every delivery; a
	 * stranger cannot guess 32 random bytes. Without a match we return a bare 200
	 * and do nothing at all: no reply, no write, no hint that the route exists.
	 */
	public function telegram(): void {
		// Always answer 200 with an empty body, otherwise Telegram keeps retrying
		// the same update and eventually disables the webhook.
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput('{}');

		$settings = new Settings($this->config);

		$expected = trim((string)$settings->get('telegram_webhook_secret', ''));
		$received = (string)($this->request->server['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');

		if ($expected === '' || $received === '' || !hash_equals($expected, $received)) {
			return;
		}

		$update = json_decode((string)file_get_contents('php://input'), true);
		if (!is_array($update)) {
			return;
		}

		$message = $update['message'] ?? $update['channel_post'] ?? null;
		if (!is_array($message) || !isset($message['chat']['id'])) {
			return;
		}

		// Groups and channels report a negative id — perfectly valid, keep the sign.
		$chatId = (int)$message['chat']['id'];
		if ($chatId === 0) {
			return;
		}

		// Anything from the update that we echo back is untrusted input; only the
		// integer chat id ever leaves this method.
		$text    = trim((string)($message['text'] ?? $message['caption'] ?? ''));
		$isStart = $text === '/start' || strpos($text, '/start ') === 0 || strpos($text, '/start@') === 0;

		$stored = trim((string)$settings->get('telegram_chat_id', ''));
		$saved  = false;

		// Claim the chat when nothing is configured yet, and let an explicit
		// /start re-point an already configured shop at a different chat.
		if ($stored === '' || $isStart) {
			Settings::writeValue($this->db, 'telegram_chat_id', (string)$chatId);
			$saved = true;
		}

		$this->load->language('extension/abandoned_cart/abandoned_cart');

		$reply = sprintf(
			(string)$this->language->get($saved ? 'text_tg_chat_saved' : 'text_tg_chat_id'),
			$chatId
		);

		$token = trim((string)$settings->get('telegram_bot_token', ''));
		(new Telegram($settings))->sendTo($token, (string)$chatId, $reply);
	}

	/**
	 * Put the stored line items back into the live cart.
	 *
	 * @return int Number of items restored.
	 */
	private function restoreCart(array $row): int {
		$items = json_decode((string)$row['cart_contents'], true);
		if (!is_array($items) || !$items) {
			return 0;
		}

		$this->load->model('catalog/product');

		$this->cart->clear();
		$restored = 0;

		foreach ($items as $item) {
			$productId = (int)($item['product_id'] ?? 0);
			if ($productId < 1) {
				continue;
			}

			$product = $this->model_catalog_product->getProduct($productId);
			if (!$product || (int)$product['status'] !== 1) {
				continue;
			}

			$this->cart->add(
				$productId,
				max(1, (int)($item['quantity'] ?? 1)),
				is_array($item['option'] ?? null) ? $item['option'] : [],
				(int)($item['subscription_plan_id'] ?? 0)
			);
			$restored++;
		}

		return $restored;
	}

	/** Pre-fill the checkout with the identity we already know. */
	private function adoptIdentity(array $row): void {
		if ($this->customer->isLogged()) {
			return;
		}

		// A cart recovered from a Viber/SMS link may have no e-mail at all.
		$phone = trim((string)($row['phone'] ?? ''));
		if ($phone !== '') {
			$this->session->data[Capture::SESSION_PHONE] = $phone;
		}

		$email = trim((string)$row['email']);
		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return;
		}

		$this->session->data[Capture::SESSION_EMAIL] = $email;

		$name = trim((string)$row['customer_name']);
		if ($name !== '') {
			$this->session->data[Capture::SESSION_NAME] = $name;
		}

		// Deliberately NOT writing session.data['customer']: OpenCart treats that
		// key as "this shopper has a customer group", and startup/customer.php
		// then feeds it straight into config_customer_group_id. A guest entry we
		// invent there changes which group specials and tax rules apply, so the
		// recovered cart silently shows different prices than the abandoned one.
		// Our own session keys above are enough to re-attach the cart.
	}
}

<?php
require_once DIR_SYSTEM . 'library/cc_abandoned_cart.php';

/**
 * Storefront endpoints: the recovery link and the guest e-mail capture.
 * OpenCart 3.x — routes are slash-separated, no namespaces.
 */
class ControllerExtensionModuleAbandonedCart extends Controller {

	/**
	 * index.php?route=extension/module/abandoned_cart/recover&token=…
	 *
	 * The token is the credential: only its SHA-256 hash is stored, the lookup
	 * is a constant-time comparison, and the token is burned the moment it is
	 * used. The address bar is cleaned by redirecting to the plain cart URL so
	 * the token is never bookmarked or leaked through a referrer header.
	 */
	public function recover() {
		$this->load->language('extension/module/abandoned_cart');

		$cartUrl = $this->url->link('checkout/cart');

		if ((string)$this->config->get(CcAbandonedCartSettings::PREFIX . 'status') !== '1') {
			$this->response->redirect($cartUrl);

			return;
		}

		$token = trim((string)(isset($this->request->get['token']) ? $this->request->get['token'] : ''));
		if ($token === '') {
			$this->response->redirect($cartUrl);

			return;
		}

		$repository = new CcAbandonedCartRepository($this->db);
		$hash       = hash('sha256', $token);
		$row        = $repository->findByTokenHash($hash);

		if (!$row || !hash_equals((string)$row['token_hash'], $hash)) {
			$this->session->data['error'] = $this->language->get('error_link_invalid');
			$this->response->redirect($cartUrl);

			return;
		}

		$expires = (string)(isset($row['token_expires_at']) ? $row['token_expires_at'] : '');
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
			$this->session->data['success'] = $this->language->get('text_cart_restored');
		} else {
			$this->session->data['error'] = $this->language->get('error_products_gone');
		}

		$this->response->redirect($cartUrl);
	}

	/**
	 * index.php?route=extension/module/abandoned_cart/capture
	 *
	 * POST endpoint used by the checkout capture script: it records the e-mail
	 * a guest has typed so the cart becomes recoverable before the order is
	 * placed. Stores nothing else and answers a bare JSON acknowledgement.
	 */
	public function capture() {
		$json = array('ok' => false);

		if ((string)$this->config->get(CcAbandonedCartSettings::PREFIX . 'status') === '1') {
			$email = trim((string)(isset($this->request->post['email']) ? $this->request->post['email'] : ''));
			$name  = trim((string)(isset($this->request->post['firstname']) ? $this->request->post['firstname'] : '') . ' ' . (string)(isset($this->request->post['lastname']) ? $this->request->post['lastname'] : ''));

			if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
				try {
					$settings = new CcAbandonedCartSettings($this->config);
					$capture  = new CcAbandonedCartCapture($this->registry, $settings, new CcAbandonedCartRepository($this->db));
					$capture->rememberEmail($email, $name);
					$json['ok'] = true;
				} catch (Exception $e) {
					$json['ok'] = false;
				}
			}
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * index.php?route=extension/module/abandoned_cart/telegram
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
	public function telegram() {
		// Always answer 200 with an empty body, otherwise Telegram keeps retrying
		// the same update and eventually disables the webhook.
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput('{}');

		$settings = new CcAbandonedCartSettings($this->config);

		$expected = trim((string)$settings->get('telegram_webhook_secret', ''));
		$received = (string)(isset($this->request->server['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']) ? $this->request->server['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] : '');

		if ($expected === '' || $received === '' || !hash_equals($expected, $received)) {
			return;
		}

		$update = json_decode((string)file_get_contents('php://input'), true);
		if (!is_array($update)) {
			return;
		}

		$message = null;
		if (isset($update['message']) && is_array($update['message'])) {
			$message = $update['message'];
		} elseif (isset($update['channel_post']) && is_array($update['channel_post'])) {
			$message = $update['channel_post'];
		}

		if (!$message || !isset($message['chat']['id'])) {
			return;
		}

		// Groups and channels report a negative id — perfectly valid, keep the sign.
		$chatId = (int)$message['chat']['id'];
		if ($chatId === 0) {
			return;
		}

		// Anything from the update that we echo back is untrusted input; only the
		// integer chat id ever leaves this method.
		$text = '';
		if (isset($message['text'])) {
			$text = trim((string)$message['text']);
		} elseif (isset($message['caption'])) {
			$text = trim((string)$message['caption']);
		}

		$isStart = $text === '/start' || strpos($text, '/start ') === 0 || strpos($text, '/start@') === 0;

		$stored = trim((string)$settings->get('telegram_chat_id', ''));
		$saved  = false;

		// Claim the chat when nothing is configured yet, and let an explicit
		// /start re-point an already configured shop at a different chat.
		if ($stored === '' || $isStart) {
			CcAbandonedCartSettings::writeValue($this->db, 'telegram_chat_id', (string)$chatId);
			$saved = true;
		}

		$this->load->language('extension/module/abandoned_cart');

		$reply = sprintf(
			(string)$this->language->get($saved ? 'text_tg_chat_saved' : 'text_tg_chat_id'),
			$chatId
		);

		$telegram = new CcAbandonedCartTelegram($settings);
		$telegram->sendTo(trim((string)$settings->get('telegram_bot_token', '')), (string)$chatId, $reply);
	}

	/**
	 * Put the stored line items back into the live cart.
	 *
	 * @return int Number of items restored.
	 */
	private function restoreCart(array $row) {
		$items = json_decode((string)$row['cart_contents'], true);
		if (!is_array($items) || !$items) {
			return 0;
		}

		$this->load->model('catalog/product');

		$this->cart->clear();
		$restored = 0;

		foreach ($items as $item) {
			$productId = (int)(isset($item['product_id']) ? $item['product_id'] : 0);
			if ($productId < 1) {
				continue;
			}

			$product = $this->model_catalog_product->getProduct($productId);
			if (!$product || (int)$product['status'] !== 1) {
				continue;
			}

			// OpenCart 3 signature: add($product_id, $qty, $option, $recurring_id).
			$this->cart->add(
				$productId,
				max(1, (int)(isset($item['quantity']) ? $item['quantity'] : 1)),
				(isset($item['option']) && is_array($item['option'])) ? $item['option'] : array(),
				(int)(isset($item['recurring_id']) ? $item['recurring_id'] : 0)
			);
			$restored++;
		}

		return $restored;
	}

	/** Pre-fill the checkout with the identity we already know. */
	private function adoptIdentity(array $row) {
		$email = trim((string)$row['email']);
		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return;
		}
		if ($this->customer->isLogged()) {
			return;
		}

		$this->session->data[CcAbandonedCartCapture::SESSION_EMAIL] = $email;

		$name = trim((string)$row['customer_name']);
		if ($name !== '') {
			$this->session->data[CcAbandonedCartCapture::SESSION_NAME] = $name;
		}

		// ⚠ Deliberately NOT writing session.data['customer']: OpenCart 3's
		// catalog/controller/startup/customer.php reads
		// $this->session->data['customer']['customer_group_id'] and feeds it
		// straight into config_customer_group_id. A guest entry we invent there
		// changes which group specials and tax rules apply, so the recovered
		// cart silently shows a different total than the abandoned one. Our own
		// session keys above are enough to re-attach the cart.
	}
}

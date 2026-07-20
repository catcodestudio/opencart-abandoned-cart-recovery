<?php
namespace Opencart\System\Library\CcAbandonedCart;

/**
 * Builds and sends the reminder e-mails through the shop's own mail transport.
 *
 * Runs in the catalog context (storefront event or cron), which is why the
 * recovery link is built from HTTP_SERVER — HTTP_CATALOG only exists in the
 * admin configuration and would produce a broken link here.
 */
class Mailer {

	private $registry;
	private Settings $settings;
	private Repository $repository;

	public function __construct($registry, Settings $settings, Repository $repository) {
		$this->registry   = $registry;
		$this->settings   = $settings;
		$this->repository = $repository;
	}

	/** Absolute recovery URL carrying a one-time token. */
	public static function buildLink(string $token): string {
		$base = defined('HTTP_SERVER') ? HTTP_SERVER : '';

		return rtrim($base, '/') . '/index.php?route=extension/abandoned_cart/abandoned_cart.recover&token=' . rawurlencode($token);
	}

	/**
	 * Send one reminder step for one cart.
	 *
	 * @param array $cart    Cart row.
	 * @param array $step    {step, delay, subject, body}
	 * @param array $strings Translated fragments: fallback_name, item_line, coupon_template, currency.
	 *
	 * @return bool Whether the transport accepted the message.
	 */
	public function send(array $cart, array $step, array $strings, string $couponCode, string $couponText): bool {
		$email = trim((string)$cart['email']);
		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return false;
		}

		$config = $this->registry->get('config');

		$token = $this->repository->issueToken(
			(int)$cart['abandoned_cart_id'],
			max(1, $this->settings->getInt('token_lifetime', 7))
		);
		$link = self::buildLink($token);

		$storeName = html_entity_decode((string)$config->get('config_name'), ENT_QUOTES, 'UTF-8');

		$replacements = [
			'{customer_name}' => $this->customerName($cart, (string)($strings['fallback_name'] ?? '')),
			'{store_name}'    => $storeName,
			'{store_url}'     => defined('HTTP_SERVER') ? HTTP_SERVER : '',
			'{cart_items}'    => $this->itemsText($cart, (string)($strings['item_line'] ?? '{name} — {qty} — {total}')),
			'{cart_total}'    => number_format((float)$cart['cart_total'], 2, '.', ' ') . ' ' . (string)$cart['currency_code'],
			'{recovery_link}' => $link,
			'{coupon_code}'   => $couponCode,
			'{coupon}'        => $couponText,
		];

		$subject = strtr((string)$step['subject'], $replacements);
		$body    = strtr((string)$step['body'], $replacements);

		$sent = $this->dispatch($email, strip_tags($subject), $this->wrapHtml($body));

		if ($sent) {
			$this->repository->update((int)$cart['abandoned_cart_id'], [
				'emails_sent'   => (int)$cart['emails_sent'] + 1,
				'last_email_at' => true,
			]);
		}

		return $sent;
	}

	private function dispatch(string $to, string $subject, string $html): bool {
		$config = $this->registry->get('config');

		try {
			// A store that never opened the mail settings has an empty engine,
			// and Mail() then dies with "Could not load mail adaptor".
			$engine = trim((string)$config->get('config_mail_engine'));
			$mail   = new \Opencart\System\Library\Mail($engine !== '' ? $engine : 'mail');

			$mail->parameter     = $config->get('config_mail_parameter');
			$mail->smtp_hostname = $config->get('config_mail_smtp_hostname');
			$mail->smtp_username = $config->get('config_mail_smtp_username');
			$mail->smtp_password = html_entity_decode((string)$config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8');
			$mail->smtp_port     = $config->get('config_mail_smtp_port');
			$mail->smtp_timeout  = $config->get('config_mail_smtp_timeout');

			$mail->setTo($to);
			$mail->setFrom((string)$config->get('config_email'));
			$mail->setSender(html_entity_decode((string)$config->get('config_name'), ENT_QUOTES, 'UTF-8'));
			$mail->setSubject($subject);
			$mail->setHtml($html);
			$mail->send();

			return true;
		} catch (\Throwable $e) {
			return false;
		}
	}

	private function customerName(array $cart, string $fallback): string {
		$name = trim((string)$cart['customer_name']);

		return $name !== '' ? $name : $fallback;
	}

	/**
	 * Plain-text item list for the {cart_items} placeholder.
	 *
	 * Line totals stored on the row are gross (product price + tax) — see the
	 * capture code — so they match what the shopper saw in the cart.
	 */
	private function itemsText(array $cart, string $lineTemplate): string {
		$items = json_decode((string)$cart['cart_contents'], true);
		if (!is_array($items) || !$items) {
			return '';
		}

		$currency = (string)$cart['currency_code'];
		$lines    = [];

		foreach ($items as $item) {
			if (!isset($item['name'])) {
				continue;
			}
			$lines[] = str_replace(
				['{name}', '{qty}', '{total}'],
				[
					(string)$item['name'],
					(string)(int)($item['quantity'] ?? 1),
					number_format((float)($item['line_total'] ?? 0), 2, '.', ' ') . ' ' . $currency,
				],
				$lineTemplate
			);
		}

		return implode("\n", $lines);
	}

	/** Turn the plain-text template into a minimal, safe HTML e-mail. */
	private function wrapHtml(string $body): string {
		$escaped = htmlspecialchars($body, ENT_QUOTES, 'UTF-8');

		// Linkify URLs so the recovery link is clickable.
		$escaped = (string)preg_replace_callback(
			'#(https?://[^\s<]+)#',
			static function (array $m): string {
				$url = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
				$safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

				return '<a href="' . $safe . '">' . $safe . '</a>';
			},
			$escaped
		);

		return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#23282d">'
			. nl2br($escaped)
			. '</div>';
	}
}

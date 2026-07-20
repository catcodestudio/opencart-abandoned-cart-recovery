<?php
namespace Opencart\System\Library\CcAbandonedCart;

/**
 * Pro: Telegram notification to the shop owner when a cart is abandoned.
 *
 * The only external host this extension ever contacts. Plain JSON POSTs to
 * https://api.telegram.org/bot<token>/sendMessage; Telegram answers
 * {"ok":true,…} or {"ok":false,"description":…}, so both the HTTP status and
 * the payload are inspected.
 *
 * Docs: https://core.telegram.org/bots/api
 */
class Telegram {

	/** Telegram hard-caps a message at 4096 UTF-8 characters. */
	private const MAX_LEN = 4096;

	private Settings $settings;

	public function __construct(Settings $settings) {
		$this->settings = $settings;
	}

	public function isEnabled(bool $isPro): bool {
		return $isPro
			&& $this->settings->isOn('telegram_enabled')
			&& trim((string)$this->settings->get('telegram_bot_token', '')) !== ''
			&& trim((string)$this->settings->get('telegram_chat_id', '')) !== '';
	}

	/**
	 * Announce one abandoned cart.
	 *
	 * @param array               $cart   Cart row.
	 * @param array<string,string> $labels Translated line labels.
	 */
	public function notifyAbandoned(array $cart, array $labels): bool {
		$lines = [
			'🛒 <b>' . self::esc((string)($labels['title'] ?? 'Abandoned cart')) . '</b>',
			self::esc((string)($labels['email'] ?? 'E-mail:')) . ' ' . self::esc((string)$cart['email']),
			self::esc((string)($labels['total'] ?? 'Total:')) . ' ' . self::esc(number_format((float)$cart['cart_total'], 2, '.', ' ') . ' ' . (string)$cart['currency_code']),
		];

		if ((string)$cart['customer_name'] !== '') {
			$lines[] = self::esc((string)($labels['customer'] ?? 'Customer:')) . ' ' . self::esc((string)$cart['customer_name']);
		}

		$items = json_decode((string)$cart['cart_contents'], true);
		if (is_array($items) && $items) {
			$lines[] = '';
			foreach ($items as $item) {
				if (!isset($item['name'])) {
					continue;
				}
				$lines[] = '• ' . self::esc((string)$item['name']) . ' × ' . (int)($item['quantity'] ?? 1);
			}
		}

		return $this->send(implode("\n", $lines));
	}

	public function send(string $text): bool {
		$token  = trim((string)$this->settings->get('telegram_bot_token', ''));
		$chatId = trim((string)$this->settings->get('telegram_chat_id', ''));

		return $this->sendTo($token, $chatId, $text);
	}

	/**
	 * Send to an explicit chat with an explicit token.
	 *
	 * The webhook handler needs this: it answers the chat that just wrote to the
	 * bot, which is not necessarily the chat id stored in the settings (that is
	 * exactly what it is about to discover).
	 */
	public function sendTo(string $token, string $chatId, string $text): bool {
		$token  = trim($token);
		$chatId = trim($chatId);
		if ($token === '' || $chatId === '') {
			return false;
		}

		if (function_exists('mb_strlen') && mb_strlen($text) > self::MAX_LEN) {
			$text = mb_substr($text, 0, self::MAX_LEN - 2) . '…';
		}

		$result = self::call($token, 'sendMessage', [
			'chat_id'                  => $chatId,
			'text'                     => $text,
			'parse_mode'               => 'HTML',
			'disable_web_page_preview' => true,
		]);

		return !empty($result['ok']);
	}

	/* ------------------------------------------------------------- webhook */

	/**
	 * A fresh webhook secret.
	 *
	 * Telegram only accepts 1–256 characters of A-Z, a-z, 0-9, _ and -, which
	 * hex satisfies. 32 random bytes is far beyond guessing range.
	 */
	public static function newSecret(): string {
		return bin2hex(random_bytes(32));
	}

	/**
	 * Point the bot at our public catalog route.
	 *
	 * Telegram will then send every update as a POST carrying the header
	 * X-Telegram-Bot-Api-Secret-Token, which the handler checks before it acts.
	 *
	 * @return array{ok:bool,description:string}
	 */
	public static function setWebhook(string $token, string $url, string $secret): array {
		return self::call($token, 'setWebhook', [
			'url'                  => $url,
			'secret_token'         => $secret,
			'allowed_updates'      => ['message', 'channel_post'],
			'drop_pending_updates' => true,
			'max_connections'      => 10,
		]);
	}

	/** @return array{ok:bool,description:string} */
	public static function deleteWebhook(string $token): array {
		return self::call($token, 'deleteWebhook', ['drop_pending_updates' => true]);
	}

	/**
	 * One JSON call to the Bot API.
	 *
	 * Telegram answers {"ok":true,…} or {"ok":false,"description":…}, so both the
	 * HTTP status and the payload are inspected and the human-readable reason is
	 * handed back for the admin screen.
	 *
	 * @return array{ok:bool,description:string,result:mixed}
	 */
	public static function call(string $token, string $method, array $payload): array {
		$token = trim($token);
		if ($token === '') {
			return ['ok' => false, 'description' => 'Empty bot token', 'result' => null];
		}

		$ch = curl_init('https://api.telegram.org/bot' . rawurlencode($token) . '/' . $method);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 20);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		$body   = (string)curl_exec($ch);
		$error  = (string)curl_error($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		$decoded = json_decode($body, true);

		if (!is_array($decoded)) {
			return [
				'ok'          => false,
				'description' => $error !== '' ? $error : 'HTTP ' . $status,
				'result'      => null,
			];
		}

		return [
			'ok'          => ($status >= 200 && $status < 300) && !empty($decoded['ok']),
			'description' => (string)($decoded['description'] ?? ''),
			'result'      => $decoded['result'] ?? null,
		];
	}

	/** Telegram HTML parse mode only allows a small tag set — escape the rest. */
	private static function esc(string $value): string {
		return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $value);
	}
}

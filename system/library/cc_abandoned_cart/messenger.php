<?php
namespace Opencart\System\Library\CcAbandonedCart;

require_once __DIR__ . '/turbosms.php';
require_once __DIR__ . '/mailer.php';

/**
 * Pro: the single Viber/SMS reminder for an abandoned cart.
 *
 * One message per cart, never a chain: a text lands on the shopper's phone
 * next to messages from family, and a second one about the same cart is what
 * makes people block the sender. The e-mail chain stays the place for
 * follow-ups.
 */
class Messenger {

	/** Viber allows 1000 characters; an SMS longer than this is 3+ paid parts. */
	private const MAX_LEN = 1000;

	private Settings $settings;
	private Repository $repository;
	private $config;

	public function __construct(Settings $settings, Repository $repository, $config) {
		$this->settings   = $settings;
		$this->repository = $repository;
		$this->config     = $config;
	}

	public function isEnabled(bool $isPro): bool {
		return $isPro
			&& $this->settings->isOn('sms_enabled')
			&& trim((string)$this->settings->get('turbosms_token', '')) !== ''
			&& $this->sendersReady();
	}

	/** The channel needs the sender name(s) TurboSMS will accept for it. */
	private function sendersReady(): bool {
		$channel = $this->channel();
		$sms     = trim((string)$this->settings->get('sms_sender', ''));
		$viber   = trim((string)$this->settings->get('viber_sender', ''));

		if ($channel === TurboSms::CHANNEL_SMS) {
			return $sms !== '';
		}
		if ($channel === TurboSms::CHANNEL_VIBER) {
			return $viber !== '';
		}

		return $sms !== '' && $viber !== '';
	}

	public function channel(): string {
		$channel = (string)$this->settings->get('sms_channel', TurboSms::CHANNEL_HYBRID);

		return in_array($channel, TurboSms::channels(), true) ? $channel : TurboSms::CHANNEL_HYBRID;
	}

	/**
	 * Is it a sensible local hour to write to somebody's phone?
	 *
	 * Uses the timezone OpenCart set for the store. from > to spans midnight
	 * (21 → 9 blocks the night); from == to disables the window.
	 */
	public function inQuietHours(?int $hour = null): bool {
		$from = min(23, max(0, $this->settings->getInt('sms_quiet_from', 21)));
		$to   = min(23, max(0, $this->settings->getInt('sms_quiet_to', 9)));
		$hour = $hour ?? (int)date('G');

		if ($from === $to) {
			return false;
		}

		return $from > $to
			? ($hour >= $from || $hour < $to)
			: ($hour >= $from && $hour < $to);
	}

	/**
	 * Build the text for one cart. The recovery link is created here, so this
	 * must only be called right before the message is actually sent.
	 */
	public function compose(array $cart, string $template, string $fallbackName): string {
		$token = $this->repository->issueMessageToken(
			(int)$cart['abandoned_cart_id'],
			max(1, $this->settings->getInt('token_lifetime', 7))
		);

		$name = trim((string)$cart['customer_name']);

		$text = strtr($template, [
			'{customer_name}' => $name !== '' ? $name : $fallbackName,
			'{store_name}'    => html_entity_decode((string)$this->config->get('config_name'), ENT_QUOTES, 'UTF-8'),
			'{cart_total}'    => number_format((float)$cart['cart_total'], 2, '.', ' ') . ' ' . (string)$cart['currency_code'],
			'{item_count}'    => (string)(int)$cart['item_count'],
			'{recovery_link}' => Mailer::buildLink($token),
			'{coupon_code}'   => (string)$cart['coupon_code'],
		]);

		$text = trim((string)preg_replace("/[ \t]+\n/", "\n", $text));

		if (function_exists('mb_strlen') && mb_strlen($text) > self::MAX_LEN) {
			$text = mb_substr($text, 0, self::MAX_LEN);
		}

		return $text;
	}

	/**
	 * Send the reminder for one cart and record the outcome on the row.
	 *
	 * The row is marked as handled even when TurboSMS refuses (bad number, no
	 * balance), otherwise every cron run would retry and bill again.
	 *
	 * @return array{ok:bool,code:int,status:string,message_id:string}
	 */
	public function send(array $cart, string $template, string $fallbackName): array {
		$text = $this->compose($cart, $template, $fallbackName);

		$result = (new TurboSms((string)$this->settings->get('turbosms_token', '')))->send(
			(string)$cart['phone'],
			$text,
			$this->channel(),
			trim((string)$this->settings->get('sms_sender', '')),
			trim((string)$this->settings->get('viber_sender', ''))
		);

		$this->repository->update((int)$cart['abandoned_cart_id'], [
			'msg_sent'    => 1,
			'last_msg_at' => true,
			'msg_status'  => substr($result['ok'] ? 'sent' : ('error ' . $result['code'] . ' ' . $result['status']), 0, 40),
		]);

		return $result;
	}
}

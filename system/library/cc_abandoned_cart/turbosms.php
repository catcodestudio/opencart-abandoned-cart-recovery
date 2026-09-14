<?php
namespace Opencart\System\Library\CcAbandonedCart;

/**
 * Pro: Viber / SMS reminders through TurboSMS (turbosms.ua).
 *
 * One JSON endpoint does all three channels:
 *
 *   POST https://api.turbosms.ua/message/send.json
 *   Authorization: Bearer <token>
 *
 *   {"recipients":["380XXXXXXXXX"], "viber":{sender,text,ttl}, "sms":{sender,text}}
 *
 * Sending both blocks is the hybrid mode: TurboSMS tries Viber first and falls
 * back to SMS when the number has no Viber or the message is not delivered
 * within `ttl`. Only the blocks for the chosen channel are sent, so a shop that
 * picked "SMS only" never pays for a Viber attempt.
 *
 * A top-level response_code of 0 does not mean the message went out — every
 * recipient carries its own response_code, and that one is what we trust.
 *
 * Docs: https://turbosms.ua/api.html
 */
class TurboSms {

	public const API = 'https://api.turbosms.ua/';

	public const CHANNEL_HYBRID = 'viber_sms';
	public const CHANNEL_VIBER  = 'viber';
	public const CHANNEL_SMS    = 'sms';

	/** Seconds Viber gets before the hybrid falls back to SMS. */
	private const VIBER_TTL = 3600;

	private string $token;

	public function __construct(string $token) {
		$this->token = trim($token);
	}

	/** @return string[] */
	public static function channels(): array {
		return [self::CHANNEL_HYBRID, self::CHANNEL_VIBER, self::CHANNEL_SMS];
	}

	/**
	 * Normalise a Ukrainian number to 380XXXXXXXXX, '' when it is not one.
	 *
	 * TurboSMS rejects anything else with code 305; shoppers type 0XX…, +380…,
	 * 80XX… and spaces or brackets, so all of those are accepted here.
	 */
	public static function normalisePhone(string $raw): string {
		$digits = (string)preg_replace('/\D+/', '', $raw);

		if (strlen($digits) === 9) {
			$digits = '380' . $digits;
		} elseif (strlen($digits) === 10 && $digits[0] === '0') {
			$digits = '38' . $digits;
		} elseif (strlen($digits) === 11 && strpos($digits, '80') === 0) {
			$digits = '3' . $digits;
		}

		return (strlen($digits) === 12 && strpos($digits, '380') === 0) ? $digits : '';
	}

	/**
	 * Send one message.
	 *
	 * @return array{ok:bool,code:int,status:string,message_id:string}
	 */
	public function send(string $phone, string $text, string $channel, string $smsSender, string $viberSender): array {
		$phone = self::normalisePhone($phone);
		if ($phone === '') {
			return ['ok' => false, 'code' => 305, 'status' => 'INVALID_PHONE', 'message_id' => ''];
		}

		$payload = ['recipients' => [$phone]];

		if ($channel !== self::CHANNEL_SMS) {
			$payload['viber'] = [
				'sender' => $viberSender,
				'text'   => $text,
				'ttl'    => self::VIBER_TTL,
			];
		}

		if ($channel !== self::CHANNEL_VIBER) {
			$payload['sms'] = [
				'sender' => $smsSender,
				'text'   => $text,
			];
		}

		$result = $this->call('message/send.json', $payload);

		$recipient = is_array($result['result'] ?? null) && isset($result['result'][0]) && is_array($result['result'][0])
			? $result['result'][0]
			: [];

		$code   = isset($recipient['response_code']) ? (int)$recipient['response_code'] : (int)$result['code'];
		$status = (string)($recipient['response_status'] ?? $result['status']);

		// 800 ACCEPTED, 801 SENT, 802/803 partial — for a single recipient any of
		// them means this number got the message.
		$accepted = $code === 0 || ($code >= 800 && $code <= 803);

		return [
			'ok'         => $accepted && (string)($recipient['message_id'] ?? '') !== '',
			'code'       => $code,
			'status'     => $status,
			'message_id' => (string)($recipient['message_id'] ?? ''),
		];
	}

	/**
	 * Account balance — doubles as the "check connection" button.
	 *
	 * @return array{ok:bool,balance:float,status:string,code:int}
	 */
	public function balance(): array {
		$result = $this->call('user/balance.json', []);

		return [
			'ok'      => $result['code'] === 0 && isset($result['result']['balance']),
			'balance' => (float)($result['result']['balance'] ?? 0),
			'status'  => $result['status'],
			'code'    => $result['code'],
		];
	}

	/**
	 * @return array{code:int,status:string,result:mixed}
	 */
	private function call(string $method, array $payload): array {
		if ($this->token === '') {
			return ['code' => 103, 'status' => 'REQUIRED_TOKEN', 'result' => null];
		}

		// CC_AC_TURBOSMS_API lets a staging shop point at a mock instead of
		// paying for real messages; production never defines it.
		$base = defined('CC_AC_TURBOSMS_API') ? (string)constant('CC_AC_TURBOSMS_API') : self::API;

		$ch = curl_init($base . $method);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, (string)json_encode($payload ?: new \stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json',
			'Accept: application/json',
			'Authorization: Bearer ' . $this->token,
		]);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 20);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		$body  = (string)curl_exec($ch);
		$error = (string)curl_error($ch);
		$http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		$decoded = json_decode($body, true);

		if (!is_array($decoded)) {
			return ['code' => -1, 'status' => $error !== '' ? $error : 'HTTP ' . $http, 'result' => null];
		}

		return [
			'code'   => (int)($decoded['response_code'] ?? -1),
			'status' => (string)($decoded['response_status'] ?? ''),
			'result' => $decoded['response_result'] ?? null,
		];
	}
}

<?php
namespace Opencart\System\Library\CcAbandonedCart;

require_once __DIR__ . '/crypto.php';

/**
 * Settings repository over the OpenCart config.
 *
 * Every key lives under module_abandoned_cart_* in the `setting` table, which
 * OpenCart loads into $this->config for admin, catalog, event and cron
 * contexts alike. Secret fields are decrypted transparently on read.
 */
class Settings {

	public const CODE   = 'module_abandoned_cart';
	public const PREFIX = 'module_abandoned_cart_';

	/** Fields encrypted at rest. */
	public const SECRET_KEYS = ['telegram_bot_token', 'license_key'];

	private $config;
	private ?array $cache = null;

	public function __construct($config) {
		$this->config = $config;
	}

	/**
	 * Every configurable value with its factory default.
	 *
	 * Message bodies are intentionally not defaulted here — they come from the
	 * language files so a fresh install speaks the shop's language.
	 */
	public static function defaults(): array {
		return [
			'status'             => '0',

			// Capture / lifecycle.
			'abandon_after'      => '60', // Minutes of inactivity before a cart counts as abandoned.
			'token_lifetime'     => '7',  // Days a recovery link stays valid.
			'retention_days'     => '90', // Days before a finished row is deleted.
			'email_cooldown'     => '3',  // Days before the same address may be mailed again.

			// Reminder 1 (free).
			'email_1_delay'      => '60',
			'email_1_subject'    => '',
			'email_1_body'       => '',

			// Reminders 2 and 3 (Pro).
			'email_2_enabled'    => '0',
			'email_2_delay'      => '1440',
			'email_2_subject'    => '',
			'email_2_body'       => '',

			'email_3_enabled'    => '0',
			'email_3_delay'      => '4320',
			'email_3_subject'    => '',
			'email_3_body'       => '',

			// Pro: personal coupon.
			'coupon_enabled'     => '0',
			'coupon_from_email'  => '2',
			'coupon_type'        => 'P', // OpenCart coupon types: P = percentage, F = fixed amount.
			'coupon_amount'      => '10',
			'coupon_expiry_days' => '7',

			// Pro: Telegram notification.
			'telegram_enabled'        => '0',
			'telegram_bot_token'      => '',
			'telegram_chat_id'        => '',
			// Shared secret Telegram echoes back in the webhook request header.
			// Non-empty means "a webhook is currently registered".
			'telegram_webhook_secret' => '',

			// Licensing.
			'license_key'        => '',
			'trial_started'      => '0',
		];
	}

	public function all(): array {
		if ($this->cache !== null) {
			return $this->cache;
		}

		$out = self::defaults();
		foreach ($out as $key => $default) {
			$value = $this->config->get(self::PREFIX . $key);
			if ($value !== null && $value !== '') {
				$out[$key] = $value;
			}
		}

		foreach (self::SECRET_KEYS as $secret) {
			if ($out[$secret] !== '') {
				$out[$secret] = Crypto::decrypt((string)$out[$secret]);
			}
		}

		$this->cache = $out;

		return $out;
	}

	public function get(string $key, $default = null) {
		$all = $this->all();

		return array_key_exists($key, $all) ? $all[$key] : $default;
	}

	public function getInt(string $key, int $default = 0): int {
		$value = $this->get($key, null);

		return ($value === null || $value === '') ? $default : (int)$value;
	}

	public function isOn(string $key): bool {
		return (string)$this->get($key, '0') === '1';
	}

	/**
	 * Encrypt every secret field of a settings payload before it is persisted.
	 * Keys are already prefixed (module_abandoned_cart_*).
	 */
	public static function encryptForStore(array $values): array {
		foreach (self::SECRET_KEYS as $secret) {
			$key = self::PREFIX . $secret;
			if (!empty($values[$key])) {
				$values[$key] = Crypto::encrypt((string)$values[$key]);
			}
		}

		return $values;
	}

	/**
	 * Write exactly ONE setting row, leaving every other key untouched.
	 *
	 * ⚠ Do not reach for the setting model here:
	 *   - editSetting() calls deleteSetting() first, so it wipes every key of
	 *     this extension that is not part of the payload — including the Pro
	 *     trial start, which would let the trial restart forever;
	 *   - editValue()/editSettingValue() only ever run an UPDATE, so on a shop
	 *     where the row does not exist yet (a chat id that was never entered by
	 *     hand) the write is silently lost.
	 * A plain UPDATE-or-INSERT on `setting` is the only variant that is both
	 * non-destructive and reliable, which matters because the Telegram webhook
	 * calls this from the storefront.
	 *
	 * @param mixed $db  OpenCart DB adapter.
	 * @param string $key Unprefixed settings key, e.g. 'telegram_chat_id'.
	 */
	public static function writeValue($db, string $key, string $value): void {
		$fullKey = self::PREFIX . $key;

		$existing = $db->query("SELECT `setting_id` FROM `" . DB_PREFIX . "setting`
			WHERE `store_id` = '0'
			  AND `code` = '" . $db->escape(self::CODE) . "'
			  AND `key` = '" . $db->escape($fullKey) . "'
			LIMIT 1");

		if ($existing->num_rows) {
			$db->query("UPDATE `" . DB_PREFIX . "setting`
				SET `value` = '" . $db->escape($value) . "', `serialized` = '0'
				WHERE `setting_id` = '" . (int)$existing->row['setting_id'] . "'");

			return;
		}

		$db->query("INSERT INTO `" . DB_PREFIX . "setting`
			SET `store_id` = '0',
			    `code` = '" . $db->escape(self::CODE) . "',
			    `key` = '" . $db->escape($fullKey) . "',
			    `value` = '" . $db->escape($value) . "',
			    `serialized` = '0'");
	}

	/**
	 * The reminder steps that are actually due to run, gated by the licence.
	 *
	 * Free installs get step 1 only; Pro adds steps 2 and 3 when enabled.
	 *
	 * @return array<int,array{step:int,delay:int,subject:string,body:string}>
	 */
	public function emailSteps(bool $isPro): array {
		$steps = [
			[
				'step'    => 1,
				'delay'   => max(1, $this->getInt('email_1_delay', 60)),
				'subject' => (string)$this->get('email_1_subject', ''),
				'body'    => (string)$this->get('email_1_body', ''),
			],
		];

		if (!$isPro) {
			return $steps;
		}

		foreach ([2, 3] as $n) {
			if (!$this->isOn('email_' . $n . '_enabled')) {
				continue;
			}
			$steps[] = [
				'step'    => $n,
				'delay'   => max(1, $this->getInt('email_' . $n . '_delay', 1440)),
				'subject' => (string)$this->get('email_' . $n . '_subject', ''),
				'body'    => (string)$this->get('email_' . $n . '_body', ''),
			];
		}

		return $steps;
	}
}

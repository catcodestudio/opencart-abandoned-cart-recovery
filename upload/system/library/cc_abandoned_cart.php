<?php
/**
 * Abandoned Cart Recovery — shared library for OpenCart 3.x (no namespaces).
 *
 * ⚠ Every class name carries the CcAbandonedCart prefix on purpose: OpenCart 3
 * ships its core classes (Cart, Customer, Mail, Config, Session…) in the global
 * namespace, so a bare `Settings`, `Repository` or `Mail` would collide and
 * fatal. The OpenCart 4 build of this extension uses
 * Opencart\System\Library\CcAbandonedCart\* instead; the code below is the same
 * logic with the namespace flattened into the class names.
 *
 * Loaded with:  require_once DIR_SYSTEM . 'library/cc_abandoned_cart.php';
 */

/* ------------------------------------------------------------------ polyfill */
// PHP 8 string helpers polyfilled for OpenCart 3 stores still on PHP 7.x.
if (!function_exists('str_starts_with')) {
	function str_starts_with($haystack, $needle) {
		return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
	}
}
if (!function_exists('str_ends_with')) {
	function str_ends_with($haystack, $needle) {
		return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
	}
}
if (!function_exists('str_contains')) {
	function str_contains($haystack, $needle) {
		return $needle === '' || strpos($haystack, $needle) !== false;
	}
}

/* -------------------------------------------------------------------- crypto */

/**
 * At-rest encryption for stored secrets (Telegram bot token, licence key).
 *
 * Uses sodium_crypto_secretbox when the extension is available, otherwise an
 * HMAC stream cipher. Values stored before encryption was introduced are
 * returned as-is so an upgrade never loses a key.
 *
 * The key is derived from DIR_SYSTEM + DB_DATABASE (OpenCart 3 has no
 * DIR_OPENCART) so a value encrypted in the admin can be decrypted from the
 * storefront event or a cron run. Moving the shop to another directory or
 * database therefore makes every stored secret unreadable — the failure counter
 * below lets the UI say exactly that instead of reporting a misleading "field
 * is empty".
 */
class CcAbandonedCartCrypto {
	const PREFIX_SODIUM = 'ccac1:';
	const PREFIX_HMAC   = 'ccac2:';

	private static $failures = 0;

	public static function resetFailures() {
		self::$failures = 0;
	}

	public static function failures() {
		return self::$failures;
	}

	public static function encrypt($plain) {
		$plain = (string)$plain;
		if ($plain === '') {
			return '';
		}
		$key = self::key();

		if (function_exists('sodium_crypto_secretbox')) {
			$nonce  = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
			$cipher = sodium_crypto_secretbox($plain, $nonce, substr($key, 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

			return self::PREFIX_SODIUM . base64_encode($nonce . $cipher);
		}

		$nonce  = random_bytes(16);
		$stream = self::hmacStream($key, $nonce, strlen($plain));
		$cipher = $plain ^ $stream;
		$mac    = hash_hmac('sha256', $nonce . $cipher, $key, true);

		return self::PREFIX_HMAC . base64_encode($nonce . $mac . $cipher);
	}

	public static function decrypt($stored) {
		$stored = (string)$stored;
		if ($stored === '') {
			return '';
		}
		$key = self::key();

		if (str_starts_with($stored, self::PREFIX_SODIUM) && function_exists('sodium_crypto_secretbox_open')) {
			$raw = base64_decode(substr($stored, strlen(self::PREFIX_SODIUM)), true);
			if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
				self::$failures++;

				return '';
			}
			$nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
			$ct    = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
			$plain = sodium_crypto_secretbox_open($ct, $nonce, substr($key, 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
			if ($plain === false) {
				self::$failures++;

				return '';
			}

			return $plain;
		}

		if (str_starts_with($stored, self::PREFIX_HMAC)) {
			$raw = base64_decode(substr($stored, strlen(self::PREFIX_HMAC)), true);
			if ($raw === false || strlen($raw) < 48) {
				self::$failures++;

				return '';
			}
			$nonce = substr($raw, 0, 16);
			$mac   = substr($raw, 16, 32);
			$ct    = substr($raw, 48);
			$calc  = hash_hmac('sha256', $nonce . $ct, $key, true);
			if (!hash_equals($mac, $calc)) {
				self::$failures++;

				return '';
			}
			$stream = self::hmacStream($key, $nonce, strlen($ct));

			return $ct ^ $stream;
		}

		// Legacy plaintext (stored before encryption was added) — return as-is.
		return $stored;
	}

	/** True when the stored value is one of ours (i.e. actually encrypted). */
	public static function isCiphertext($stored) {
		$stored = (string)$stored;

		return str_starts_with($stored, self::PREFIX_SODIUM) || str_starts_with($stored, self::PREFIX_HMAC);
	}

	private static function key() {
		// OpenCart 3 defines DIR_SYSTEM identically in admin/config.php and in
		// the storefront config.php, so admin, catalog and cron derive the very
		// same key.
		$root     = defined('DIR_SYSTEM') ? DIR_SYSTEM : __DIR__;
		$material = $root . (defined('DB_DATABASE') ? DB_DATABASE : '');

		return hash('sha256', 'CatCodeAbandonedCart|' . $material, true);
	}

	private static function hmacStream($key, $nonce, $len) {
		$out     = '';
		$counter = 0;
		while (strlen($out) < $len) {
			$out .= hash_hmac('sha256', $nonce . pack('N', $counter++), $key, true);
		}

		return substr($out, 0, $len);
	}
}

/* ------------------------------------------------------------------ settings */

/**
 * Settings repository over the OpenCart config.
 *
 * Every key lives under module_abandoned_cart_* in the `setting` table, which
 * OpenCart loads into $this->config for admin, catalog, event and cron
 * contexts alike. Secret fields are decrypted transparently on read.
 */
class CcAbandonedCartSettings {

	const CODE   = 'module_abandoned_cart';
	const PREFIX = 'module_abandoned_cart_';

	private $config;
	private $cache = null;

	public function __construct($config) {
		$this->config = $config;
	}

	/** Fields encrypted at rest. */
	public static function secretKeys() {
		return array('telegram_bot_token', 'license_key');
	}

	/**
	 * Every configurable value with its factory default.
	 *
	 * Message bodies are intentionally not defaulted here — they come from the
	 * language files so a fresh install speaks the shop's language.
	 */
	public static function defaults() {
		return array(
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
		);
	}

	public function all() {
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

		foreach (self::secretKeys() as $secret) {
			if ($out[$secret] !== '') {
				$out[$secret] = CcAbandonedCartCrypto::decrypt((string)$out[$secret]);
			}
		}

		$this->cache = $out;

		return $out;
	}

	public function get($key, $default = null) {
		$all = $this->all();

		return array_key_exists($key, $all) ? $all[$key] : $default;
	}

	public function getInt($key, $default = 0) {
		$value = $this->get($key, null);

		return ($value === null || $value === '') ? (int)$default : (int)$value;
	}

	public function isOn($key) {
		return (string)$this->get($key, '0') === '1';
	}

	/**
	 * Encrypt every secret field of a settings payload before it is persisted.
	 * Keys are already prefixed (module_abandoned_cart_*).
	 */
	public static function encryptForStore(array $values) {
		foreach (self::secretKeys() as $secret) {
			$key = self::PREFIX . $secret;
			if (!empty($values[$key])) {
				$values[$key] = CcAbandonedCartCrypto::encrypt((string)$values[$key]);
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
	 *   - editSettingValue() only ever runs an UPDATE, so on a shop where the
	 *     row does not exist yet (a chat id that was never entered by hand) the
	 *     write is silently lost.
	 * A plain UPDATE-or-INSERT on `setting` is the only variant that is both
	 * non-destructive and reliable, which matters because the Telegram webhook
	 * calls this from the storefront.
	 *
	 * @param mixed  $db    OpenCart DB adapter.
	 * @param string $key   Unprefixed settings key, e.g. 'telegram_chat_id'.
	 * @param string $value Value to store.
	 */
	public static function writeValue($db, $key, $value) {
		$fullKey = self::PREFIX . (string)$key;

		$existing = $db->query("SELECT `setting_id` FROM `" . DB_PREFIX . "setting`
			WHERE `store_id` = '0'
			  AND `code` = '" . $db->escape(self::CODE) . "'
			  AND `key` = '" . $db->escape($fullKey) . "'
			LIMIT 1");

		if ($existing->num_rows) {
			$db->query("UPDATE `" . DB_PREFIX . "setting`
				SET `value` = '" . $db->escape((string)$value) . "', `serialized` = '0'
				WHERE `setting_id` = '" . (int)$existing->row['setting_id'] . "'");

			return;
		}

		$db->query("INSERT INTO `" . DB_PREFIX . "setting`
			SET `store_id` = '0',
			    `code` = '" . $db->escape(self::CODE) . "',
			    `key` = '" . $db->escape($fullKey) . "',
			    `value` = '" . $db->escape((string)$value) . "',
			    `serialized` = '0'");
	}

	/**
	 * The reminder steps that are actually due to run, gated by the licence.
	 *
	 * Free installs get step 1 only; Pro adds steps 2 and 3 when enabled.
	 */
	public function emailSteps($isPro) {
		$steps = array(
			array(
				'step'    => 1,
				'delay'   => max(1, $this->getInt('email_1_delay', 60)),
				'subject' => (string)$this->get('email_1_subject', ''),
				'body'    => (string)$this->get('email_1_body', ''),
			),
		);

		if (!$isPro) {
			return $steps;
		}

		foreach (array(2, 3) as $n) {
			if (!$this->isOn('email_' . $n . '_enabled')) {
				continue;
			}
			$steps[] = array(
				'step'    => $n,
				'delay'   => max(1, $this->getInt('email_' . $n . '_delay', 1440)),
				'subject' => (string)$this->get('email_' . $n . '_subject', ''),
				'body'    => (string)$this->get('email_' . $n . '_body', ''),
			);
		}

		return $steps;
	}
}

/* ------------------------------------------------------------------- license */

/**
 * Pro licence gate — CatCode standard trial model.
 *
 * Pro is unlocked when EITHER a licence key is present OR the install is still
 * inside its automatic 7-day free trial. When the trial ends only the Pro
 * features lock; the free tier keeps working.
 *
 * The trial start timestamp is written by the admin controller's install() as
 * a direct INSERT into `setting` (OpenCart 3's editSettingValue() only performs
 * an UPDATE, so on a fresh install the write would be silently lost) and is
 * carried through every save() (editSetting() deletes all rows of the code
 * first, so an unmentioned key would be wiped and the trial would restart
 * forever).
 */
class CcAbandonedCartLicense {

	const TRIAL_DAYS = 7;

	public static function key($config) {
		$stored = (string)$config->get(CcAbandonedCartSettings::PREFIX . 'license_key');

		return $stored === '' ? '' : trim(CcAbandonedCartCrypto::decrypt($stored));
	}

	public static function hasLicense($config) {
		return self::key($config) !== '';
	}

	public static function trialStarted($config) {
		return (int)$config->get(CcAbandonedCartSettings::PREFIX . 'trial_started');
	}

	public static function trialActive($config) {
		$started = self::trialStarted($config);
		if ($started <= 0) {
			// The extension has not been installed through the installer yet —
			// treat it as day one rather than locking the merchant out.
			return true;
		}

		return (time() - $started) < self::TRIAL_DAYS * 86400;
	}

	public static function trialDaysLeft($config) {
		$started = self::trialStarted($config);
		if ($started <= 0) {
			return self::TRIAL_DAYS;
		}

		return max(0, self::TRIAL_DAYS - (int)floor((time() - $started) / 86400));
	}

	public static function isPro($config) {
		return self::hasLicense($config) || self::trialActive($config);
	}
}

/* ---------------------------------------------------------------- repository */

/**
 * Data access layer for the abandoned cart table.
 *
 * Instantiated with the OpenCart DB object: new CcAbandonedCartRepository($this->db).
 *
 * ⚠ On the PDO driver $this->db->escape() does NOT return an escaped string —
 * it returns a placeholder token that is bound when the query runs. Gluing
 * literal characters onto it (the classic LIKE '"..escape($s).."%') produces a
 * broken statement. Every LIKE pattern below is therefore assembled first and
 * escaped as one complete value.
 */
class CcAbandonedCartRepository {

	const STATUS_ACTIVE    = 'active';
	const STATUS_ABANDONED = 'abandoned';
	const STATUS_RECOVERED = 'recovered';
	const STATUS_LOST      = 'lost';

	private $db;

	public function __construct($db) {
		$this->db = $db;
	}

	public static function table() {
		return DB_PREFIX . 'abandoned_cart';
	}

	public static function statuses() {
		return array(self::STATUS_ACTIVE, self::STATUS_ABANDONED, self::STATUS_RECOVERED, self::STATUS_LOST);
	}

	/* ---------------------------------------------------------------- write */

	/**
	 * Insert or refresh the single row belonging to one shopper session.
	 *
	 * @return int Row id, 0 on failure.
	 */
	public function upsert(array $data) {
		$sessionKey = isset($data['session_key']) ? (string)$data['session_key'] : '';
		if ($sessionKey === '') {
			return 0;
		}

		$existing = $this->findBySession($sessionKey);

		$email = isset($data['email']) ? (string)$data['email'] : '';
		$name  = isset($data['customer_name']) ? (string)$data['customer_name'] : '';

		if ($existing) {
			// Never overwrite a known e-mail or name with an empty one.
			if ($email === '') {
				$email = (string)$existing['email'];
			}
			if ($name === '') {
				$name = (string)$existing['customer_name'];
			}

			$sets = array(
				"`customer_id` = " . (int)(isset($data['customer_id']) ? $data['customer_id'] : 0),
				"`email` = '" . $this->db->escape($email) . "'",
				"`customer_name` = '" . $this->db->escape($name) . "'",
				"`cart_contents` = '" . $this->db->escape(isset($data['cart_contents']) ? (string)$data['cart_contents'] : '') . "'",
				"`cart_total` = " . (float)(isset($data['cart_total']) ? $data['cart_total'] : 0),
				"`currency_code` = '" . $this->db->escape(isset($data['currency_code']) ? (string)$data['currency_code'] : '') . "'",
				"`language_id` = " . (int)(isset($data['language_id']) ? $data['language_id'] : 0),
				"`store_id` = " . (int)(isset($data['store_id']) ? $data['store_id'] : 0),
				"`item_count` = " . (int)(isset($data['item_count']) ? $data['item_count'] : 0),
				"`updated_at` = NOW()",
			);

			// A shopper who is active again leaves the abandoned queue.
			if ((string)$existing['status'] === self::STATUS_ABANDONED) {
				$sets[] = "`status` = '" . self::STATUS_ACTIVE . "'";
				$sets[] = "`abandoned_at` = NULL";
			}

			$this->db->query("UPDATE `" . self::table() . "` SET " . implode(', ', $sets) . " WHERE `abandoned_cart_id` = " . (int)$existing['abandoned_cart_id']);

			return (int)$existing['abandoned_cart_id'];
		}

		$this->db->query("INSERT INTO `" . self::table() . "` SET
			`session_key` = '" . $this->db->escape($sessionKey) . "',
			`customer_id` = " . (int)(isset($data['customer_id']) ? $data['customer_id'] : 0) . ",
			`email` = '" . $this->db->escape($email) . "',
			`customer_name` = '" . $this->db->escape($name) . "',
			`cart_contents` = '" . $this->db->escape(isset($data['cart_contents']) ? (string)$data['cart_contents'] : '') . "',
			`cart_total` = " . (float)(isset($data['cart_total']) ? $data['cart_total'] : 0) . ",
			`currency_code` = '" . $this->db->escape(isset($data['currency_code']) ? (string)$data['currency_code'] : '') . "',
			`language_id` = " . (int)(isset($data['language_id']) ? $data['language_id'] : 0) . ",
			`store_id` = " . (int)(isset($data['store_id']) ? $data['store_id'] : 0) . ",
			`item_count` = " . (int)(isset($data['item_count']) ? $data['item_count'] : 0) . ",
			`status` = '" . self::STATUS_ACTIVE . "',
			`created_at` = NOW(),
			`updated_at` = NOW()");

		return (int)$this->db->getLastId();
	}

	/**
	 * Update a whitelisted set of columns.
	 *
	 * @param array $fields Column => value. NULL writes SQL NULL.
	 */
	public function update($id, array $fields) {
		$id = (int)$id;

		$strings = array('email', 'customer_name', 'cart_contents', 'currency_code', 'status', 'token_hash', 'coupon_code');
		$ints    = array('customer_id', 'item_count', 'emails_sent', 'coupon_id', 'recovered_order_id', 'language_id', 'store_id');
		$floats  = array('cart_total', 'recovered_total');
		$dates   = array('token_expires_at', 'last_email_at', 'abandoned_at', 'recovered_at');

		$sets = array('`updated_at` = NOW()');

		foreach ($fields as $column => $value) {
			if (in_array($column, $strings, true)) {
				$sets[] = "`" . $column . "` = '" . $this->db->escape((string)$value) . "'";
			} elseif (in_array($column, $ints, true)) {
				$sets[] = "`" . $column . "` = " . (int)$value;
			} elseif (in_array($column, $floats, true)) {
				$sets[] = "`" . $column . "` = " . (float)$value;
			} elseif (in_array($column, $dates, true)) {
				if ($value === null || $value === '') {
					$sets[] = "`" . $column . "` = NULL";
				} elseif ($value === true || $value === 'NOW()') {
					$sets[] = "`" . $column . "` = NOW()";
				} else {
					$sets[] = "`" . $column . "` = '" . $this->db->escape((string)$value) . "'";
				}
			}
		}

		if (count($sets) < 2) {
			return;
		}

		$this->db->query("UPDATE `" . self::table() . "` SET " . implode(', ', $sets) . " WHERE `abandoned_cart_id` = " . $id);
	}

	public function delete($id) {
		$this->db->query("DELETE FROM `" . self::table() . "` WHERE `abandoned_cart_id` = " . (int)$id);
	}

	/* ----------------------------------------------------------------- read */

	public function findBySession($sessionKey) {
		$row = $this->db->query("SELECT * FROM `" . self::table() . "` WHERE `session_key` = '" . $this->db->escape((string)$sessionKey) . "' LIMIT 1")->row;

		return $row ? $row : null;
	}

	public function find($id) {
		$row = $this->db->query("SELECT * FROM `" . self::table() . "` WHERE `abandoned_cart_id` = " . (int)$id . " LIMIT 1")->row;

		return $row ? $row : null;
	}

	public function findByTokenHash($hash) {
		$hash = (string)$hash;
		if ($hash === '') {
			return null;
		}
		$row = $this->db->query("SELECT * FROM `" . self::table() . "` WHERE `token_hash` = '" . $this->db->escape($hash) . "' LIMIT 1")->row;

		return $row ? $row : null;
	}

	public function findByCouponCode($code) {
		$code = (string)$code;
		if ($code === '') {
			return null;
		}
		$row = $this->db->query("SELECT * FROM `" . self::table() . "` WHERE `coupon_code` = '" . $this->db->escape($code) . "' LIMIT 1")->row;

		return $row ? $row : null;
	}

	/**
	 * Active carts idle for longer than $minutes — the scan promotes them to
	 * "abandoned". Rows without an e-mail address can never be recovered, so
	 * they are skipped.
	 */
	public function staleActive($minutes, $limit = 200) {
		return $this->db->query("SELECT * FROM `" . self::table() . "`
			WHERE `status` = '" . self::STATUS_ACTIVE . "'
			  AND `email` <> ''
			  AND `updated_at` < DATE_SUB(NOW(), INTERVAL " . max(1, (int)$minutes) . " MINUTE)
			ORDER BY `updated_at` ASC LIMIT " . max(1, (int)$limit))->rows;
	}

	/** Abandoned carts still eligible for another reminder. */
	public function pendingReminders($maxStep, $limit = 50) {
		return $this->db->query("SELECT * FROM `" . self::table() . "`
			WHERE `status` = '" . self::STATUS_ABANDONED . "'
			  AND `email` <> ''
			  AND `emails_sent` < " . max(1, (int)$maxStep) . "
			ORDER BY `abandoned_at` ASC LIMIT " . max(1, (int)$limit))->rows;
	}

	/**
	 * Throttle: has this address already been mailed by us within $days days?
	 */
	public function emailedRecently($email, $days, $excludeId = 0) {
		$email = (string)$email;
		$days  = (int)$days;
		if ($days < 1 || $email === '') {
			return false;
		}
		$row = $this->db->query("SELECT `abandoned_cart_id` FROM `" . self::table() . "`
			WHERE `email` = '" . $this->db->escape($email) . "'
			  AND `abandoned_cart_id` <> " . (int)$excludeId . "
			  AND `last_email_at` IS NOT NULL
			  AND `last_email_at` > DATE_SUB(NOW(), INTERVAL " . $days . " DAY)
			LIMIT 1")->row;

		return !empty($row);
	}

	/**
	 * Open carts belonging to one buyer — flipped to "recovered" once an order
	 * with the same e-mail or customer id arrives.
	 */
	public function openForCustomer($email, $customerId) {
		$email      = (string)$email;
		$customerId = (int)$customerId;

		$where = array();
		if ($email !== '') {
			$where[] = "`email` = '" . $this->db->escape($email) . "'";
		}
		if ($customerId > 0) {
			$where[] = "`customer_id` = " . $customerId;
		}
		if (!$where) {
			return array();
		}

		return $this->db->query("SELECT * FROM `" . self::table() . "`
			WHERE `status` IN ('" . self::STATUS_ACTIVE . "', '" . self::STATUS_ABANDONED . "')
			  AND (" . implode(' OR ', $where) . ")
			LIMIT 50")->rows;
	}

	/**
	 * Paged listing for the admin table.
	 *
	 * @return array {items, total}
	 */
	public function paged($status, $search, $perPage, $page, $sort, $order) {
		$allowedSort = array('created_at', 'updated_at', 'cart_total', 'email', 'status');
		if (!in_array($sort, $allowedSort, true)) {
			$sort = 'updated_at';
		}
		$order   = (strtolower($order) === 'asc') ? 'ASC' : 'DESC';
		$perPage = max(1, (int)$perPage);
		$offset  = (max(1, (int)$page) - 1) * $perPage;

		$where = array('1 = 1');
		if (in_array($status, self::statuses(), true)) {
			$where[] = "`status` = '" . $this->db->escape($status) . "'";
		}
		if ((string)$search !== '') {
			// ⚠ The whole LIKE pattern — wildcards included — is escaped as one
			// value; on PDO escape() yields a bound placeholder and appending
			// '%' to it outside the quotes would corrupt the statement.
			$where[] = "`email` LIKE '" . $this->db->escape('%' . $search . '%') . "'";
		}
		$clause = implode(' AND ', $where);

		$items = $this->db->query("SELECT * FROM `" . self::table() . "` WHERE " . $clause . "
			ORDER BY `" . $sort . "` " . $order . " LIMIT " . (int)$offset . ", " . (int)$perPage)->rows;

		$countRow = $this->db->query("SELECT COUNT(*) AS total FROM `" . self::table() . "` WHERE " . $clause)->row;
		$total    = (int)(isset($countRow['total']) ? $countRow['total'] : 0);

		return array('items' => $items, 'total' => $total);
	}

	/** Every row, oldest first — used by the CSV export. */
	public function allForExport($status) {
		$where = '1 = 1';
		if (in_array($status, self::statuses(), true)) {
			$where = "`status` = '" . $this->db->escape($status) . "'";
		}

		return $this->db->query("SELECT * FROM `" . self::table() . "` WHERE " . $where . " ORDER BY `abandoned_cart_id` ASC")->rows;
	}

	/**
	 * Counts per status plus recovered revenue.
	 *
	 * @return array {abandoned, recovered, revenue, rate}
	 */
	public function stats() {
		$row = $this->db->query("SELECT COUNT(*) AS total FROM `" . self::table() . "`
			WHERE `status` IN ('" . self::STATUS_ABANDONED . "', '" . self::STATUS_RECOVERED . "', '" . self::STATUS_LOST . "')")->row;
		$abandoned = (int)(isset($row['total']) ? $row['total'] : 0);

		$row = $this->db->query("SELECT COUNT(*) AS total FROM `" . self::table() . "`
			WHERE `status` = '" . self::STATUS_RECOVERED . "'")->row;
		$recovered = (int)(isset($row['total']) ? $row['total'] : 0);

		$row = $this->db->query("SELECT SUM(`recovered_total`) AS total FROM `" . self::table() . "`
			WHERE `status` = '" . self::STATUS_RECOVERED . "'")->row;
		$revenue = (float)(isset($row['total']) ? $row['total'] : 0);

		return array(
			'abandoned' => $abandoned,
			'recovered' => $recovered,
			'revenue'   => $revenue,
			'rate'      => $abandoned > 0 ? round(($recovered / $abandoned) * 100, 1) : 0.0,
		);
	}

	/** Abandoned carts whose recovery window has closed become "lost". */
	public function expireStale($days) {
		$this->db->query("UPDATE `" . self::table() . "`
			SET `status` = '" . self::STATUS_LOST . "', `token_hash` = '', `token_expires_at` = NULL, `updated_at` = NOW()
			WHERE `status` = '" . self::STATUS_ABANDONED . "'
			  AND `abandoned_at` IS NOT NULL
			  AND `abandoned_at` < DATE_SUB(NOW(), INTERVAL " . max(1, (int)$days) . " DAY)");
	}

	/** Retention: physically remove rows older than $days days. */
	public function purgeOld($days) {
		$days = (int)$days;
		if ($days < 1) {
			return;
		}
		$this->db->query("DELETE FROM `" . self::table() . "`
			WHERE `updated_at` < DATE_SUB(NOW(), INTERVAL " . $days . " DAY)");
	}

	/**
	 * Issue a fresh one-time recovery token.
	 *
	 * Only the SHA-256 hash is persisted; the plaintext is returned once, for
	 * the outgoing e-mail, and never stored.
	 */
	public function issueToken($id, $lifetimeDays) {
		$token = bin2hex(random_bytes(16));

		$this->db->query("UPDATE `" . self::table() . "`
			SET `token_hash` = '" . $this->db->escape(hash('sha256', $token)) . "',
			    `token_expires_at` = DATE_ADD(NOW(), INTERVAL " . max(1, (int)$lifetimeDays) . " DAY),
			    `updated_at` = NOW()
			WHERE `abandoned_cart_id` = " . (int)$id);

		return $token;
	}

	/** Burn a token once it has been used (or found expired). */
	public function clearToken($id) {
		$this->db->query("UPDATE `" . self::table() . "`
			SET `token_hash` = '', `token_expires_at` = NULL, `updated_at` = NOW()
			WHERE `abandoned_cart_id` = " . (int)$id);
	}

	/** Create the table. Safe to call repeatedly. */
	public function install() {
		$this->db->query("CREATE TABLE IF NOT EXISTS `" . self::table() . "` (
			`abandoned_cart_id` INT(11) NOT NULL AUTO_INCREMENT,
			`store_id` INT(11) NOT NULL DEFAULT 0,
			`session_key` VARCHAR(64) NOT NULL,
			`customer_id` INT(11) NOT NULL DEFAULT 0,
			`email` VARCHAR(96) NOT NULL DEFAULT '',
			`customer_name` VARCHAR(128) NOT NULL DEFAULT '',
			`cart_contents` LONGTEXT NULL,
			`cart_total` DECIMAL(15,4) NOT NULL DEFAULT 0,
			`currency_code` VARCHAR(3) NOT NULL DEFAULT '',
			`language_id` INT(11) NOT NULL DEFAULT 0,
			`item_count` INT(11) NOT NULL DEFAULT 0,
			`status` VARCHAR(20) NOT NULL DEFAULT 'active',
			`token_hash` VARCHAR(64) NOT NULL DEFAULT '',
			`token_expires_at` DATETIME DEFAULT NULL,
			`emails_sent` TINYINT(1) NOT NULL DEFAULT 0,
			`last_email_at` DATETIME DEFAULT NULL,
			`coupon_code` VARCHAR(64) NOT NULL DEFAULT '',
			`coupon_id` INT(11) NOT NULL DEFAULT 0,
			`recovered_order_id` INT(11) NOT NULL DEFAULT 0,
			`recovered_total` DECIMAL(15,4) NOT NULL DEFAULT 0,
			`created_at` DATETIME NOT NULL,
			`updated_at` DATETIME NOT NULL,
			`abandoned_at` DATETIME DEFAULT NULL,
			`recovered_at` DATETIME DEFAULT NULL,
			PRIMARY KEY (`abandoned_cart_id`),
			UNIQUE KEY `session_key` (`session_key`),
			KEY `email` (`email`),
			KEY `status` (`status`),
			KEY `token_hash` (`token_hash`),
			KEY `updated_at` (`updated_at`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
	}
}

/* ------------------------------------------------------------------- capture */

/**
 * Cart capture.
 *
 * Logged-in shoppers are recorded as soon as they have something in the cart.
 * Guests are recorded only once they have handed over an e-mail address —
 * either through the checkout register/guest step or through the small
 * storefront script that posts the typed address to our catalog endpoint. A
 * guest who never gives an address is never written to the database at all.
 */
class CcAbandonedCartCapture {

	/** Session key holding the address a guest typed at checkout. */
	const SESSION_EMAIL = 'abandoned_cart_email';
	const SESSION_NAME  = 'abandoned_cart_name';

	private $registry;
	private $settings;
	private $repository;

	public function __construct($registry, $settings, $repository) {
		$this->registry   = $registry;
		$this->settings   = $settings;
		$this->repository = $repository;
	}

	/**
	 * Identifier for the current shopper. Registered customers are keyed by
	 * customer id so the row survives a new session cookie.
	 */
	public function sessionKey() {
		$customer = $this->registry->get('customer');
		if ($customer && $customer->isLogged()) {
			return 'customer-' . (int)$customer->getId();
		}

		$session = $this->registry->get('session');
		$id      = $session ? (string)$session->getId() : '';

		return $id === '' ? '' : 'session-' . substr($id, 0, 52);
	}

	/** Remember an address a guest typed, then persist the cart. */
	public function rememberEmail($email, $name = '') {
		$email = trim((string)$email);
		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return;
		}

		$session = $this->registry->get('session');
		if ($session) {
			$session->data[self::SESSION_EMAIL] = $email;
			if ((string)$name !== '') {
				$session->data[self::SESSION_NAME] = (string)$name;
			}
		}

		$this->store();
	}

	/** Forget the captured guest identity (after a completed order). */
	public function forgetEmail() {
		$session = $this->registry->get('session');
		if (!$session) {
			return;
		}
		unset($session->data[self::SESSION_EMAIL], $session->data[self::SESSION_NAME]);
	}

	/** The address we currently know for this shopper, '' when unknown. */
	public function currentEmail() {
		$customer = $this->registry->get('customer');
		if ($customer && $customer->isLogged()) {
			return (string)$customer->getEmail();
		}

		$session = $this->registry->get('session');
		if (!$session) {
			return '';
		}
		if (!empty($session->data[self::SESSION_EMAIL])) {
			return (string)$session->data[self::SESSION_EMAIL];
		}
		// OpenCart's guest checkout keeps the typed details here.
		if (!empty($session->data['guest']['email'])) {
			return (string)$session->data['guest']['email'];
		}
		if (!empty($session->data['customer']['email'])) {
			return (string)$session->data['customer']['email'];
		}

		return '';
	}

	private function currentName() {
		$customer = $this->registry->get('customer');
		if ($customer && $customer->isLogged()) {
			return trim($customer->getFirstName() . ' ' . $customer->getLastName());
		}

		$session = $this->registry->get('session');
		if (!$session) {
			return '';
		}
		if (!empty($session->data[self::SESSION_NAME])) {
			return (string)$session->data[self::SESSION_NAME];
		}
		foreach (array('guest', 'customer') as $bucket) {
			if (!empty($session->data[$bucket]) && is_array($session->data[$bucket])) {
				$who  = $session->data[$bucket];
				$name = trim((isset($who['firstname']) ? (string)$who['firstname'] : '') . ' ' . (isset($who['lastname']) ? (string)$who['lastname'] : ''));
				if ($name !== '') {
					return $name;
				}
			}
		}

		return '';
	}

	/**
	 * Write the current cart to the table.
	 *
	 * Called from the storefront events after every cart mutation and after the
	 * e-mail address becomes known.
	 */
	public function store() {
		$cart = $this->registry->get('cart');
		if (!$cart) {
			return;
		}

		$key = $this->sessionKey();
		if ($key === '') {
			return;
		}

		$email = $this->currentEmail();

		// No address means nothing to recover and no personal data to collect.
		if ($email === '') {
			return;
		}

		$items = $this->snapshot();
		$row   = $this->repository->findBySession($key);

		if (!$items) {
			// An emptied cart that never converted is not worth keeping.
			if ($row && (string)$row['status'] === CcAbandonedCartRepository::STATUS_ACTIVE) {
				$this->repository->delete((int)$row['abandoned_cart_id']);
			}

			return;
		}

		$total = 0.0;
		$count = 0;
		foreach ($items as $item) {
			$total += (float)$item['line_total'];
			$count += (int)$item['quantity'];
		}

		$customer = $this->registry->get('customer');
		$config   = $this->registry->get('config');
		$session  = $this->registry->get('session');

		// ⚠ OpenCart's Cart\Currency has no getCode(): the active code lives in
		// the session (shopper's pick) and falls back to the store default.
		$currency = ($session && !empty($session->data['currency']))
			? (string)$session->data['currency']
			: (string)$config->get('config_currency');

		$this->repository->upsert(array(
			'session_key'   => $key,
			'customer_id'   => ($customer && $customer->isLogged()) ? (int)$customer->getId() : 0,
			'email'         => $email,
			'customer_name' => $this->currentName(),
			'cart_contents' => (string)json_encode($items, JSON_UNESCAPED_UNICODE),
			'cart_total'    => $total,
			'currency_code' => $currency,
			'language_id'   => (int)$config->get('config_language_id'),
			'store_id'      => (int)$config->get('config_store_id'),
			'item_count'    => $count,
		));

		if ($session) {
			$session->data['abandoned_cart_touched'] = time();
		}
	}

	/** Drop the row for the current session (order placed from another path). */
	public function dropActive() {
		$key = $this->sessionKey();
		if ($key === '') {
			return;
		}
		$row = $this->repository->findBySession($key);
		if ($row && (string)$row['status'] === CcAbandonedCartRepository::STATUS_ACTIVE) {
			$this->repository->delete((int)$row['abandoned_cart_id']);
		}
	}

	/**
	 * A serialisable snapshot of the cart, enough to rebuild it later.
	 *
	 * ⚠ OpenCart cart/order product prices are stored WITHOUT tax. The line
	 * total kept here is the gross figure the shopper actually sees, produced
	 * by the tax library from the net unit price and the product's tax class.
	 */
	public function snapshot() {
		$cart   = $this->registry->get('cart');
		$tax    = $this->registry->get('tax');
		$config = $this->registry->get('config');

		$withTax = (bool)$config->get('config_tax');
		$out     = array();

		foreach ($cart->getProducts() as $product) {
			$quantity   = (int)(isset($product['quantity']) ? $product['quantity'] : 1);
			$netUnit    = (float)(isset($product['price']) ? $product['price'] : 0);
			$taxClassId = (int)(isset($product['tax_class_id']) ? $product['tax_class_id'] : (isset($product['tax']) ? $product['tax'] : 0));

			$grossUnit = ($tax && $taxClassId > 0)
				? (float)$tax->calculate($netUnit, $taxClassId, $withTax)
				: $netUnit;

			// OpenCart 3 calls subscriptions "recurring profiles".
			$recurringId = 0;
			if (!empty($product['recurring']) && is_array($product['recurring']) && isset($product['recurring']['recurring_id'])) {
				$recurringId = (int)$product['recurring']['recurring_id'];
			} elseif (isset($product['recurring_id'])) {
				$recurringId = (int)$product['recurring_id'];
			}

			$out[] = array(
				'product_id'   => (int)(isset($product['product_id']) ? $product['product_id'] : 0),
				'name'         => (string)(isset($product['name']) ? $product['name'] : ''),
				'model'        => (string)(isset($product['model']) ? $product['model'] : ''),
				'quantity'     => $quantity,
				'option'       => $this->normaliseOptions(isset($product['option']) ? $product['option'] : array()),
				'recurring_id' => $recurringId,
				'price'        => round($grossUnit, 4),
				'line_total'   => round($grossUnit * $quantity, 4),
			);
		}

		return $out;
	}

	/**
	 * Turn the resolved option list returned by Cart::getProducts() back into
	 * the raw {product_option_id: value} shape Cart::add() expects.
	 */
	private function normaliseOptions($options) {
		if (!is_array($options)) {
			return array();
		}

		$out = array();
		foreach ($options as $option) {
			$id   = (int)(isset($option['product_option_id']) ? $option['product_option_id'] : 0);
			$type = (string)(isset($option['type']) ? $option['type'] : '');
			if ($id < 1) {
				continue;
			}

			if (in_array($type, array('select', 'radio', 'image'), true)) {
				$out[$id] = (int)(isset($option['product_option_value_id']) ? $option['product_option_value_id'] : 0);
			} elseif ($type === 'checkbox') {
				$out[$id][] = (int)(isset($option['product_option_value_id']) ? $option['product_option_value_id'] : 0);
			} else {
				$out[$id] = (string)(isset($option['value']) ? $option['value'] : '');
			}
		}

		return $out;
	}
}

/* -------------------------------------------------------------------- mailer */

/**
 * Builds and sends the reminder e-mails through the shop's own mail transport.
 *
 * Runs in the catalog context (storefront event or cron), which is why the
 * recovery link is built from HTTP_SERVER — HTTP_CATALOG only exists in the
 * admin configuration and would produce a broken link here.
 */
class CcAbandonedCartMailer {

	private $registry;
	private $settings;
	private $repository;

	public function __construct($registry, $settings, $repository) {
		$this->registry   = $registry;
		$this->settings   = $settings;
		$this->repository = $repository;
	}

	/** Absolute recovery URL carrying a one-time token. */
	public static function buildLink($token) {
		$base = defined('HTTP_SERVER') ? HTTP_SERVER : '';

		return rtrim($base, '/') . '/index.php?route=extension/module/abandoned_cart/recover&token=' . rawurlencode((string)$token);
	}

	/**
	 * Send one reminder step for one cart.
	 *
	 * @param array  $cart       Cart row.
	 * @param array  $step       {step, delay, subject, body}
	 * @param array  $strings    Translated fragments: fallback_name, item_line.
	 * @param string $couponCode Personal coupon code, '' when none.
	 * @param string $couponText Human sentence describing the coupon.
	 *
	 * @return bool Whether the transport accepted the message.
	 */
	public function send(array $cart, array $step, array $strings, $couponCode, $couponText) {
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

		$replacements = array(
			'{customer_name}' => $this->customerName($cart, isset($strings['fallback_name']) ? (string)$strings['fallback_name'] : ''),
			'{store_name}'    => $storeName,
			'{store_url}'     => defined('HTTP_SERVER') ? HTTP_SERVER : '',
			'{cart_items}'    => $this->itemsText($cart, isset($strings['item_line']) ? (string)$strings['item_line'] : '{name} — {qty} — {total}'),
			'{cart_total}'    => number_format((float)$cart['cart_total'], 2, '.', ' ') . ' ' . (string)$cart['currency_code'],
			'{recovery_link}' => $link,
			'{coupon_code}'   => (string)$couponCode,
			'{coupon}'        => (string)$couponText,
		);

		$subject = strtr((string)$step['subject'], $replacements);
		$body    = strtr((string)$step['body'], $replacements);

		$sent = $this->dispatch($email, strip_tags($subject), $this->wrapHtml($body));

		if ($sent) {
			$this->repository->update((int)$cart['abandoned_cart_id'], array(
				'emails_sent'   => (int)$cart['emails_sent'] + 1,
				'last_email_at' => true,
			));
		}

		return $sent;
	}

	private function dispatch($to, $subject, $html) {
		$config = $this->registry->get('config');

		try {
			// ⚠ A store that never opened the mail settings has an empty engine,
			// and Mail() then dies with "Could not load mail adaptor".
			$engine = trim((string)$config->get('config_mail_engine'));
			$mail   = new Mail($engine !== '' ? $engine : 'mail');

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
		} catch (Throwable $e) {
			return false;
		} catch (Exception $e) {
			return false;
		}
	}

	private function customerName(array $cart, $fallback) {
		$name = trim((string)$cart['customer_name']);

		return $name !== '' ? $name : (string)$fallback;
	}

	/**
	 * Plain-text item list for the {cart_items} placeholder.
	 *
	 * Line totals stored on the row are gross (product price + tax) — see the
	 * capture code — so they match what the shopper saw in the cart.
	 */
	private function itemsText(array $cart, $lineTemplate) {
		$items = json_decode((string)$cart['cart_contents'], true);
		if (!is_array($items) || !$items) {
			return '';
		}

		$currency = (string)$cart['currency_code'];
		$lines    = array();

		foreach ($items as $item) {
			if (!isset($item['name'])) {
				continue;
			}
			$lines[] = str_replace(
				array('{name}', '{qty}', '{total}'),
				array(
					(string)$item['name'],
					(string)(int)(isset($item['quantity']) ? $item['quantity'] : 1),
					number_format((float)(isset($item['line_total']) ? $item['line_total'] : 0), 2, '.', ' ') . ' ' . $currency,
				),
				(string)$lineTemplate
			);
		}

		return implode("\n", $lines);
	}

	/** Turn the plain-text template into a minimal, safe HTML e-mail. */
	private function wrapHtml($body) {
		$escaped = htmlspecialchars((string)$body, ENT_QUOTES, 'UTF-8');

		// Linkify URLs so the recovery link is clickable.
		$escaped = (string)preg_replace_callback(
			'#(https?://[^\s<]+)#',
			function ($m) {
				$url  = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
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

/* ------------------------------------------------------------------- coupons */

/**
 * Pro: personal discount coupon attached to the follow-up reminders.
 *
 * A real OpenCart coupon row is created in `oc_coupon` — one per cart, single
 * use, with its own expiry date — and the code is stored back on the cart row.
 * OpenCart coupons have no native e-mail restriction, so the binding to the
 * shopper is enforced by the storefront event
 * (catalog/model/marketing/coupon/getCoupon/after): a coupon issued by this
 * extension is only handed out when the current session belongs to the address
 * it was generated for.
 */
class CcAbandonedCartCoupons {

	private $db;
	private $settings;
	private $repository;

	public function __construct($db, $settings, $repository) {
		$this->db         = $db;
		$this->settings   = $settings;
		$this->repository = $repository;
	}

	/** Should this reminder step carry a coupon? */
	public function appliesToStep($step, $isPro) {
		if (!$isPro || !$this->settings->isOn('coupon_enabled')) {
			return false;
		}

		return (int)$step >= max(1, $this->settings->getInt('coupon_from_email', 2));
	}

	/**
	 * Coupon code for this cart, created on first use and reused afterwards.
	 *
	 * @return string Empty when disabled or on failure.
	 */
	public function codeForCart(array $cart, $step, $isPro) {
		if (!$this->appliesToStep($step, $isPro)) {
			return '';
		}

		$existing = trim((string)(isset($cart['coupon_code']) ? $cart['coupon_code'] : ''));
		if ($existing !== '') {
			return $existing;
		}

		$email = trim((string)$cart['email']);
		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return '';
		}

		$amount = (float)$this->settings->get('coupon_amount', 10);
		if ($amount <= 0) {
			return '';
		}

		$type = (string)$this->settings->get('coupon_type', 'P') === 'F' ? 'F' : 'P';
		$days = max(1, $this->settings->getInt('coupon_expiry_days', 7));
		$code = 'BACK-' . strtoupper(bin2hex(random_bytes(4)));

		$this->db->query("INSERT INTO `" . DB_PREFIX . "coupon` SET
			`name` = '" . $this->db->escape('Abandoned cart recovery - ' . $email) . "',
			`code` = '" . $this->db->escape($code) . "',
			`type` = '" . $this->db->escape($type) . "',
			`discount` = " . $amount . ",
			`logged` = 0,
			`shipping` = 0,
			`total` = 0,
			`date_start` = CURDATE(),
			`date_end` = DATE_ADD(CURDATE(), INTERVAL " . $days . " DAY),
			`uses_total` = 1,
			`uses_customer` = 1,
			`status` = 1,
			`date_added` = NOW()");

		$couponId = (int)$this->db->getLastId();

		$this->repository->update((int)$cart['abandoned_cart_id'], array(
			'coupon_code' => $code,
			'coupon_id'   => $couponId,
		));

		return $code;
	}

	/**
	 * Is $code one of ours, and does it belong to $email / $customerId?
	 *
	 * Returns true when the coupon is not ours at all (nothing to guard) or
	 * when the shopper matches; false when it belongs to somebody else.
	 */
	public function isAllowedFor($code, $email, $customerId) {
		$row = $this->repository->findByCouponCode($code);
		if (!$row) {
			return true;
		}

		if ((string)$email !== '' && strcasecmp((string)$row['email'], (string)$email) === 0) {
			return true;
		}

		return (int)$customerId > 0 && (int)$row['customer_id'] === (int)$customerId;
	}

	/** Human-readable coupon sentence for the {coupon} placeholder. */
	public function describe($code, $template, $currencySuffix = '') {
		if ((string)$code === '') {
			return '';
		}

		$amount = (float)$this->settings->get('coupon_amount', 10);
		$days   = max(1, $this->settings->getInt('coupon_expiry_days', 7));
		$number = rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
		$value  = (string)$this->settings->get('coupon_type', 'P') === 'F'
			? $number . ' ' . $currencySuffix
			: $number . '%';

		return str_replace(array('{value}', '{code}', '{days}'), array(trim($value), (string)$code, (string)$days), (string)$template);
	}

	/** Remove coupons this extension generated for rows that no longer exist. */
	public function purgeOrphans() {
		$this->db->query("DELETE c FROM `" . DB_PREFIX . "coupon` c
			LEFT JOIN `" . CcAbandonedCartRepository::table() . "` a ON a.`coupon_id` = c.`coupon_id`
			WHERE c.`code` LIKE '" . $this->db->escape('BACK-%') . "'
			  AND a.`abandoned_cart_id` IS NULL
			  AND c.`date_end` < CURDATE()");
	}
}

/* ------------------------------------------------------------------ telegram */

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
class CcAbandonedCartTelegram {

	/** Telegram hard-caps a message at 4096 UTF-8 characters. */
	const MAX_LEN = 4096;

	private $settings;

	public function __construct($settings) {
		$this->settings = $settings;
	}

	public function isEnabled($isPro) {
		return $isPro
			&& $this->settings->isOn('telegram_enabled')
			&& trim((string)$this->settings->get('telegram_bot_token', '')) !== ''
			&& trim((string)$this->settings->get('telegram_chat_id', '')) !== '';
	}

	/**
	 * Announce one abandoned cart.
	 *
	 * @param array $cart   Cart row.
	 * @param array $labels Translated line labels.
	 */
	public function notifyAbandoned(array $cart, array $labels) {
		$lines = array(
			'🛒 <b>' . self::esc(isset($labels['title']) ? (string)$labels['title'] : 'Abandoned cart') . '</b>',
			self::esc(isset($labels['email']) ? (string)$labels['email'] : 'E-mail:') . ' ' . self::esc((string)$cart['email']),
			self::esc(isset($labels['total']) ? (string)$labels['total'] : 'Total:') . ' ' . self::esc(number_format((float)$cart['cart_total'], 2, '.', ' ') . ' ' . (string)$cart['currency_code']),
		);

		if ((string)$cart['customer_name'] !== '') {
			$lines[] = self::esc(isset($labels['customer']) ? (string)$labels['customer'] : 'Customer:') . ' ' . self::esc((string)$cart['customer_name']);
		}

		$items = json_decode((string)$cart['cart_contents'], true);
		if (is_array($items) && $items) {
			$lines[] = '';
			foreach ($items as $item) {
				if (!isset($item['name'])) {
					continue;
				}
				$lines[] = '• ' . self::esc((string)$item['name']) . ' × ' . (int)(isset($item['quantity']) ? $item['quantity'] : 1);
			}
		}

		return $this->send(implode("\n", $lines));
	}

	public function send($text) {
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
	public function sendTo($token, $chatId, $text) {
		$token  = trim((string)$token);
		$chatId = trim((string)$chatId);
		$text   = (string)$text;
		if ($token === '' || $chatId === '') {
			return false;
		}

		if (function_exists('mb_strlen') && mb_strlen($text) > self::MAX_LEN) {
			$text = mb_substr($text, 0, self::MAX_LEN - 2) . '…';
		}

		$result = self::call($token, 'sendMessage', array(
			'chat_id'                  => $chatId,
			'text'                     => $text,
			'parse_mode'               => 'HTML',
			'disable_web_page_preview' => true,
		));

		return !empty($result['ok']);
	}

	/* ------------------------------------------------------------- webhook */

	/**
	 * A fresh webhook secret.
	 *
	 * Telegram only accepts 1–256 characters of A-Z, a-z, 0-9, _ and -, which
	 * hex satisfies. 32 random bytes is far beyond guessing range.
	 */
	public static function newSecret() {
		if (function_exists('random_bytes')) {
			return bin2hex(random_bytes(32));
		}

		// PHP 5.x fallback for the oldest shops OpenCart 3 still runs on.
		return bin2hex(openssl_random_pseudo_bytes(32));
	}

	/**
	 * Point the bot at our public catalog route.
	 *
	 * Telegram will then send every update as a POST carrying the header
	 * X-Telegram-Bot-Api-Secret-Token, which the handler checks before it acts.
	 */
	public static function setWebhook($token, $url, $secret) {
		return self::call($token, 'setWebhook', array(
			'url'                  => (string)$url,
			'secret_token'         => (string)$secret,
			'allowed_updates'      => array('message', 'channel_post'),
			'drop_pending_updates' => true,
			'max_connections'      => 10,
		));
	}

	public static function deleteWebhook($token) {
		return self::call($token, 'deleteWebhook', array('drop_pending_updates' => true));
	}

	/**
	 * One JSON call to the Bot API.
	 *
	 * Telegram answers {"ok":true,…} or {"ok":false,"description":…}, so both the
	 * HTTP status and the payload are inspected and the human-readable reason is
	 * handed back for the admin screen.
	 */
	public static function call($token, $method, array $payload) {
		$token = trim((string)$token);
		if ($token === '') {
			return array('ok' => false, 'description' => 'Empty bot token', 'result' => null);
		}

		$ch = curl_init('https://api.telegram.org/bot' . rawurlencode($token) . '/' . $method);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Accept: application/json'));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 20);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		$body   = (string)curl_exec($ch);
		$error  = (string)curl_error($ch);
		$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		$decoded = json_decode($body, true);

		if (!is_array($decoded)) {
			return array(
				'ok'          => false,
				'description' => $error !== '' ? $error : 'HTTP ' . $status,
				'result'      => null,
			);
		}

		return array(
			'ok'          => ($status >= 200 && $status < 300) && !empty($decoded['ok']),
			'description' => isset($decoded['description']) ? (string)$decoded['description'] : '',
			'result'      => isset($decoded['result']) ? $decoded['result'] : null,
		);
	}

	/** Telegram HTML parse mode only allows a small tag set — escape the rest. */
	private static function esc($value) {
		return str_replace(array('&', '<', '>'), array('&amp;', '&lt;', '&gt;'), (string)$value);
	}
}

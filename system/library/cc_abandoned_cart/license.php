<?php
namespace Opencart\System\Library\CcAbandonedCart;

/**
 * Pro licence gate + CatCode licence-server client.
 *
 * Pro is unlocked only by a key the server confirmed as valid. A fresh install
 * is plain free: nothing switches itself on, so a merchant who only wanted the
 * free reminder never sees the coupon, the Telegram alert and the reminder
 * chain appear and then vanish a week later.
 *
 * Two ways to get a key, both ending in the same place:
 *
 *  1. Purchase on catcode.com.ua → paste the key → activate().
 *  2. "Try 7 days" tab → e-mail → startTrial() mints a real 7-day key through
 *     POST /wp-json/catcode/v1/trial and activates it right away.
 *
 * When the key expires only the Pro features lock — capturing carts and the
 * first reminder keep working, and nothing already captured is touched.
 *
 * Storage (module_abandoned_cart_* settings):
 *  - license_key         — current key
 *  - license_status      — 'valid' | 'invalid' | '' (never checked)
 *  - license_checked_at  — 'Y-m-d H:i:s' of the last server roundtrip
 *  - license_expires_at  — expiry the server reported
 *  - license_data        — JSON of the last response (diagnostics)
 *  - trial_started       — timestamp the merchant started the trial, 0 = never
 *
 * Override endpoints for staging via CATCODE_LICENSE_API / CATCODE_TRIAL_API.
 */
class License {
	/** Canonical module slug — MUST match post_name of the module CPT. */
	const EXPECTED_SLUG = 'opencart-abandoned-cart-recovery';

	/** Free trial length in days. Paid licences are annual (1–5 years). */
	const TRIAL_DAYS = 7;

	/** Days the cached 'valid' stays authoritative while the server is down. */
	const GRACE_DAYS = 14;

	/** Setting prefix. */
	const PREFIX = 'module_abandoned_cart_';

	/** Setting group code used by editSetting(). */
	const GROUP = 'module_abandoned_cart';

	private const DEFAULT_LICENSE_API = 'https://catcode.com.ua/wp-json/catcode/v1/license';
	private const DEFAULT_TRIAL_API   = 'https://catcode.com.ua/wp-json/catcode/v1/trial';

	/** How often the stored key is silently re-verified in the background. */
	private const VERIFY_EVERY = 86400;

	private const HTTP_TIMEOUT = 8;

	// -----------------------------------------------------------------------
	// State
	// -----------------------------------------------------------------------

	/**
	 * Pro is on when the server said 'valid' and that answer is still fresh.
	 * An unreachable server does not revoke Pro for GRACE_DAYS — a merchant must
	 * not lose paid features because our site had an outage.
	 */
	public static function isPro(\Opencart\System\Engine\Registry $registry): bool {
		$config = $registry->get('config');

		if ((string)$config->get(self::PREFIX . 'license_status') !== 'valid') {
			return false;
		}

		$checkedAt = (string)$config->get(self::PREFIX . 'license_checked_at');

		if ($checkedAt === '') {
			return false;
		}

		return (time() - (int)strtotime($checkedAt)) <= self::GRACE_DAYS * 86400;
	}

	/** A key is stored, whether or not the server has blessed it yet. */
	public static function hasKey(\Opencart\System\Engine\Registry $registry): bool {
		return trim((string)$registry->get('config')->get(self::PREFIX . 'license_key')) !== '';
	}

	/**
	 * Offline shape check. Used only to reject obvious typos before spending a
	 * roundtrip — it never grants Pro on its own.
	 */
	public static function keyLooksValid(string $key): bool {
		$key = strtoupper(trim($key));

		if ($key === '') {
			return false;
		}

		return (bool)preg_match('/^[A-Z0-9]{4,10}(-[A-Z0-9]{4,10}){1,6}$/', $key);
	}

	/** True only after the merchant opted in; a never-started trial is not active. */
	public static function trialActive(\Opencart\System\Engine\Registry $registry): bool {
		return self::trialStarted($registry) > 0 && self::isPro($registry);
	}

	/** Timestamp the merchant started the trial, or 0 when never started. */
	public static function trialStarted(\Opencart\System\Engine\Registry $registry): int {
		return (int)$registry->get('config')->get(self::PREFIX . 'trial_started');
	}

	/** Offered once per install, and never while a key is already in place. */
	public static function trialAvailable(\Opencart\System\Engine\Registry $registry): bool {
		return self::trialStarted($registry) <= 0 && !self::hasKey($registry);
	}

	public static function trialDaysLeft(\Opencart\System\Engine\Registry $registry): int {
		$started = self::trialStarted($registry);

		if ($started <= 0) {
			return self::TRIAL_DAYS;
		}

		return max(0, self::TRIAL_DAYS - (int)floor((time() - $started) / 86400));
	}

	/** Expiry the server reported for the current key ('' when open-ended). */
	public static function expiresAt(\Opencart\System\Engine\Registry $registry): string {
		$raw = (string)$registry->get('config')->get(self::PREFIX . 'license_expires_at');

		if ($raw === '') {
			return '';
		}

		$ts = (int)strtotime($raw);

		return $ts > 0 ? date('Y-m-d', $ts) : '';
	}

	/**
	 * Settings the form never posts: they are written by this client and by the
	 * dismiss button. save() must carry them over untouched, otherwise every
	 * "Save" would clear the activated licence.
	 */
	public static function serverOwned(): array {
		return [
			self::PREFIX . 'license_key',
			self::PREFIX . 'license_status',
			self::PREFIX . 'license_checked_at',
			self::PREFIX . 'license_expires_at',
			self::PREFIX . 'license_data',
			self::PREFIX . 'trial_started',
			self::PREFIX . 'notice_dismissed',
		];
	}

	// -----------------------------------------------------------------------
	// Server calls
	// -----------------------------------------------------------------------

	/**
	 * Activate a pasted key.
	 *
	 * Two steps: verify first, then activate. verify() consumes no activation
	 * slot, so a key meant for another CatCode product (or a typo) doesn't burn
	 * one of the merchant's sites.
	 */
	public static function activate(\Opencart\System\Engine\Registry $registry, string $key): array {
		$key = trim($key);

		if ($key === '') {
			return ['ok' => false, 'error' => 'missing_key'];
		}

		$pre = self::call($registry, $key, 'verify');

		if (empty($pre['ok'])) {
			return $pre;
		}

		$result = self::call($registry, $key, 'activate');
		self::store($registry, $key, $result);

		return $result;
	}

	/** Re-verify the stored key. */
	public static function verify(\Opencart\System\Engine\Registry $registry): array {
		$key = trim((string)$registry->get('config')->get(self::PREFIX . 'license_key'));

		if ($key === '') {
			return ['ok' => false, 'error' => 'no_key'];
		}

		$result = self::call($registry, $key, 'verify');
		self::store($registry, $key, $result);

		return $result;
	}

	/** Release the server-side slot and clear the local cache. */
	public static function deactivate(\Opencart\System\Engine\Registry $registry): array {
		$key    = trim((string)$registry->get('config')->get(self::PREFIX . 'license_key'));
		$result = $key === '' ? ['ok' => true, 'note' => 'no_key_stored'] : self::call($registry, $key, 'deactivate');

		self::write($registry, [
			self::PREFIX . 'license_key'        => '',
			self::PREFIX . 'license_status'     => '',
			self::PREFIX . 'license_checked_at' => '',
			self::PREFIX . 'license_expires_at' => '',
			self::PREFIX . 'license_data'       => '',
		]);

		return $result;
	}

	/**
	 * Mint a 7-day trial key for this shop and activate it. `trial_started` is
	 * written only after a key really arrived, so a rejected request leaves the
	 * trial on offer.
	 */
	public static function startTrial(\Opencart\System\Engine\Registry $registry, string $email): array {
		$email = trim($email);

		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return ['ok' => false, 'error' => 'bad_email'];
		}

		if (!self::trialAvailable($registry)) {
			return ['ok' => false, 'error' => 'trial_used_site'];
		}

		$response = self::post(self::trialEndpoint(), [
			'email'       => $email,
			'module_slug' => self::EXPECTED_SLUG,
			'site_url'    => self::siteUrl($registry),
		]);

		if (empty($response['ok']) || empty($response['key'])) {
			return $response;
		}

		$key        = (string)$response['key'];
		$activation = self::call($registry, $key, 'activate');

		self::store($registry, $key, $activation, [self::PREFIX . 'trial_started' => (string)time()]);

		$activation['key']        = $key;
		$activation['expires_at'] = (string)($response['expires_at'] ?? ($activation['expires_at'] ?? ''));

		return $activation;
	}

	/**
	 * Re-verify at most once a day, quietly. OpenCart has no reliable cron, so
	 * this piggybacks on the admin page load; a network failure is a no-op
	 * thanks to the grace window in isPro().
	 */
	public static function maybeVerify(\Opencart\System\Engine\Registry $registry): bool {
		$config = $registry->get('config');

		if (trim((string)$config->get(self::PREFIX . 'license_key')) === '') {
			return false;
		}

		$checkedAt = (string)$config->get(self::PREFIX . 'license_checked_at');

		if ($checkedAt !== '' && (time() - (int)strtotime($checkedAt)) < self::VERIFY_EVERY) {
			return false;
		}

		self::verify($registry);

		return true;
	}

	// -----------------------------------------------------------------------
	// Plumbing
	// -----------------------------------------------------------------------

	public static function licenseEndpoint(): string {
		return defined('CATCODE_LICENSE_API') ? (string)constant('CATCODE_LICENSE_API') : self::DEFAULT_LICENSE_API;
	}

	public static function trialEndpoint(): string {
		return defined('CATCODE_TRIAL_API') ? (string)constant('CATCODE_TRIAL_API') : self::DEFAULT_TRIAL_API;
	}

	/** One licence-API call, with the per-product guard applied to the answer. */
	private static function call(\Opencart\System\Engine\Registry $registry, string $key, string $action): array {
		$data = self::post(self::licenseEndpoint(), [
			'key'      => $key,
			'site_url' => self::siteUrl($registry),
			'action'   => $action,
		]);

		// Per-product enforcement: a key issued for another CatCode module must
		// not unlock Abandoned Cart Pro. Older server builds may omit product_slug —
		// absent means "no info, trust it"; present-and-different hard-fails.
		if (!empty($data['ok']) && isset($data['product_slug']) && $data['product_slug'] !== ''
			&& $data['product_slug'] !== self::EXPECTED_SLUG) {
			$data['ok']    = false;
			$data['error'] = 'wrong_product';
		}

		// The server leaves `error` blank on a failed verify — normalize it so
		// the UI can say what to do instead of a generic "key invalid".
		if (empty($data['ok']) && empty($data['error'])) {
			if (!empty($data['expired'])) {
				$data['error'] = 'expired';
			} elseif ((int)($data['http'] ?? 0) === 401) {
				$data['error'] = 'invalid_key';
			} elseif ((int)($data['http'] ?? 0) === 403) {
				$data['error'] = 'limit_reached';
			}
		}

		return $data;
	}

	/**
	 * POST JSON and decode. A transport failure returns ok=false without an
	 * error the server never sent, so an outage never flips status to invalid.
	 */
	private static function post(string $url, array $payload): array {
		if (!function_exists('curl_init')) {
			return ['ok' => false, 'error' => 'no_curl', 'http' => 0];
		}

		$ch = curl_init($url);

		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
			CURLOPT_POSTFIELDS     => (string)json_encode($payload),
			CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
			CURLOPT_CONNECTTIMEOUT => 5,
		]);

		$raw  = curl_exec($ch);
		$err  = curl_error($ch);
		$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($raw === false) {
			return ['ok' => false, 'error' => 'network', 'message' => $err, 'http' => 0];
		}

		$data = json_decode((string)$raw, true);

		if (!is_array($data)) {
			return ['ok' => false, 'error' => 'bad_response', 'http' => $http, 'raw' => substr((string)$raw, 0, 500)];
		}

		$data['http']        = $http;
		$data['verified_at'] = date('Y-m-d H:i:s');

		return $data;
	}

	/**
	 * Storefront URL as scheme+host. The server normalizes the same way, so
	 * http vs https and trailing slashes don't burn a second activation slot.
	 */
	private static function siteUrl(\Opencart\System\Engine\Registry $registry): string {
		$base = '';

		foreach (['HTTPS_CATALOG', 'HTTPS_SERVER', 'HTTP_CATALOG', 'HTTP_SERVER'] as $name) {
			if (defined($name)) {
				$base = (string)constant($name);

				break;
			}
		}

		if ($base === '') {
			$base = (string)$registry->get('config')->get('config_url');
		}

		$parts = parse_url($base);

		if (!$parts || empty($parts['host'])) {
			return '';
		}

		$scheme = strtolower(isset($parts['scheme']) ? $parts['scheme'] : 'https');

		return $scheme . '://' . strtolower($parts['host']);
	}

	/**
	 * True when the call never reached a verdict: DNS down, connection refused,
	 * a 5xx, or a body that isn't our JSON. Anything here says nothing about the
	 * key itself, so it must not be stored as a verdict.
	 */
	private static function isTransportFailure(array $result): bool {
		if (!empty($result['ok'])) {
			return false;
		}

		$error = (string)(isset($result['error']) ? $result['error'] : '');

		if (in_array($error, ['network', 'no_curl', 'bad_response'], true)) {
			return true;
		}

		$http = (int)(isset($result['http']) ? $result['http'] : 0);

		return $http === 0 || $http >= 500;
	}

	/**
	 * Persist key + verdict + raw response.
	 *
	 * A transport failure is NOT a verdict. Writing `invalid` on an unreachable
	 * server made GRACE_DAYS dead code: the first failed daily poll during a
	 * CatCode outage closed Pro on every paying shop, because isPro() checks
	 * status before it ever looks at the grace window. So an outage leaves the
	 * previous status, expiry and checked_at untouched — the grace window keeps
	 * running from the last real answer and closes on its own after GRACE_DAYS.
	 */
	private static function store(\Opencart\System\Engine\Registry $registry, string $key, array $result, array $extra = []): void {
		if (self::isTransportFailure($result) && self::hasKey($registry)) {
			self::write($registry, array_merge([
				self::PREFIX . 'license_key'  => $key,
				self::PREFIX . 'license_data' => (string)json_encode($result, JSON_UNESCAPED_UNICODE),
			], $extra));

			return;
		}

		self::write($registry, array_merge([
			self::PREFIX . 'license_key'        => $key,
			self::PREFIX . 'license_status'     => !empty($result['ok']) ? 'valid' : 'invalid',
			self::PREFIX . 'license_checked_at' => (string)(isset($result['verified_at']) ? $result['verified_at'] : date('Y-m-d H:i:s')),
			self::PREFIX . 'license_expires_at' => (string)(isset($result['expires_at']) ? $result['expires_at'] : ''),
			self::PREFIX . 'license_data'       => (string)json_encode($result, JSON_UNESCAPED_UNICODE),
		], $extra));
	}

	/**
	 * Merge a handful of keys into the module's setting group.
	 *
	 * editSetting() replaces the whole group, so the current rows are read back
	 * first — otherwise saving a licence would wipe the Telegram credentials and
	 * the reminder templates. The live $config is patched too, so code further
	 * down the same request already sees the new state.
	 */
	private static function write(\Opencart\System\Engine\Registry $registry, array $values): void {
		$loader = $registry->get('load');
		$loader->model('setting/setting');

		$model   = $registry->get('model_setting_setting');
		$current = $model->getSetting(self::GROUP);

		if (!is_array($current)) {
			$current = [];
		}

		$config = $registry->get('config');

		foreach ($values as $key => $value) {
			$current[$key] = $value;
			$config->set($key, $value);
		}

		$model->editSetting(self::GROUP, $current);
	}
}

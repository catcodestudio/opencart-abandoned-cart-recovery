<?php
namespace Opencart\System\Library\CcAbandonedCart;

/**
 * At-rest encryption for stored secrets (Telegram bot token, licence key).
 *
 * Uses sodium_crypto_secretbox when the extension is available, otherwise an
 * HMAC stream cipher. Values stored before encryption was introduced are
 * returned as-is so an upgrade never loses a key.
 *
 * The key is derived from DIR_OPENCART + DB_DATABASE so a value encrypted in
 * the admin can be decrypted from the storefront event or a cron run. Moving
 * the shop to another directory or database therefore makes every stored
 * secret unreadable — the failure counter below lets the UI say exactly that
 * instead of reporting a misleading "field is empty".
 */
class Crypto {
	private const PREFIX_SODIUM = 'ccac1:';
	private const PREFIX_HMAC   = 'ccac2:';

	private static int $failures = 0;

	public static function resetFailures(): void {
		self::$failures = 0;
	}

	public static function failures(): int {
		return self::$failures;
	}

	public static function encrypt(string $plain): string {
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

	public static function decrypt(string $stored): string {
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
	public static function isCiphertext(string $stored): bool {
		return str_starts_with($stored, self::PREFIX_SODIUM) || str_starts_with($stored, self::PREFIX_HMAC);
	}

	private static function key(): string {
		$root     = defined('DIR_OPENCART') ? DIR_OPENCART : (defined('DIR_SYSTEM') ? DIR_SYSTEM : __DIR__);
		$material = $root . (defined('DB_DATABASE') ? DB_DATABASE : '');

		return hash('sha256', 'CatCodeAbandonedCart|' . $material, true);
	}

	private static function hmacStream(string $key, string $nonce, int $len): string {
		$out     = '';
		$counter = 0;
		while (strlen($out) < $len) {
			$out .= hash_hmac('sha256', $nonce . pack('N', $counter++), $key, true);
		}

		return substr($out, 0, $len);
	}
}

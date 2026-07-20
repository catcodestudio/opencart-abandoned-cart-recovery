<?php
namespace Opencart\System\Library\CcAbandonedCart;

require_once __DIR__ . '/crypto.php';

/**
 * Pro licence gate — CatCode standard trial model.
 *
 * Pro is unlocked when EITHER a licence key is present OR the install is still
 * inside its automatic 7-day free trial. When the trial ends only the Pro
 * features lock; the free tier keeps working.
 *
 * The trial start timestamp is written by the admin controller's install() as
 * a direct INSERT into `setting` (editValue() only performs an UPDATE, so on a
 * fresh install the write would be silently lost) and is carried through every
 * save() (editSetting() deletes all rows of the code first, so an unmentioned
 * key would be wiped and the trial would restart forever).
 */
class License {

	public const TRIAL_DAYS = 7;

	public static function key($config): string {
		$stored = (string)$config->get(Settings::PREFIX . 'license_key');

		return $stored === '' ? '' : trim(Crypto::decrypt($stored));
	}

	public static function hasLicense($config): bool {
		return self::key($config) !== '';
	}

	public static function trialStarted($config): int {
		return (int)$config->get(Settings::PREFIX . 'trial_started');
	}

	public static function trialActive($config): bool {
		$started = self::trialStarted($config);
		if ($started <= 0) {
			// The extension has not been installed through the installer yet —
			// treat it as day one rather than locking the merchant out.
			return true;
		}

		return (time() - $started) < self::TRIAL_DAYS * 86400;
	}

	public static function trialDaysLeft($config): int {
		$started = self::trialStarted($config);
		if ($started <= 0) {
			return self::TRIAL_DAYS;
		}

		return max(0, self::TRIAL_DAYS - (int)floor((time() - $started) / 86400));
	}

	public static function isPro($config): bool {
		return self::hasLicense($config) || self::trialActive($config);
	}
}

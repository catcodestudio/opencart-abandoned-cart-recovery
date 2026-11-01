<?php
/**
 * Copy of a module's settings that survives an update.
 *
 * OpenCart 4 updates an extension only this way: uninstall it, remove the package
 * in the Installer, upload the new one, install. The first step makes the core
 * delete the group `<type>_<code>` before the extension's own uninstall() runs.
 *
 * Every request to the settings screen mirrors the group to one shared table, which
 * stays after an uninstall. install() brings the rows back.
 * Rows are copied as they are, so encrypted fields stay encrypted.
 *
 * The same file ships in several extensions, hence the class_exists() guard.
 */
if (!class_exists('CcSettingsKeep', false)) {
	final class CcSettingsKeep {
		private const TABLE = 'cc_settings_keep';

		/** Mirror the group once this request has finished with it. */
		public static function mirrorAfter($db, string $code): void {
			register_shutdown_function(static function () use ($db, $code): void {
				self::mirror($db, $code);
			});
		}

		/**
		 * Called first in install(). The rows go back once install() has finished. An empty
		 * group gets everything back, over whatever defaults install() wrote. A group that
		 * is not empty keeps its values and gets only the rows it lacks: the module's own
		 * uninstall() may have left a value on purpose, and install() may run on a live shop.
		 */
		public static function restoreAfter($db, string $code): void {
			try {
				$all = !self::rows($db, $code);
			} catch (\Throwable $e) {
				return;
			}

			register_shutdown_function(static function () use ($db, $code, $all): void {
				self::restore($db, $code, $all);
			});
		}

		private static function ensure($db): void {
			$db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . self::TABLE . "` (
				`code` varchar(128) NOT NULL,
				`data` mediumtext NOT NULL,
				`saved_at` datetime DEFAULT NULL,
				PRIMARY KEY (`code`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
		}

		private static function mirror($db, string $code): void {
			try {
				$rows = self::rows($db, $code);

				if (!$rows) {
					return;
				}

				$json = json_encode($rows, JSON_UNESCAPED_UNICODE);

				if (!is_string($json)) {
					return;
				}

				self::ensure($db);
				$db->query("REPLACE INTO `" . DB_PREFIX . self::TABLE . "` SET `code` = '" . $db->escape($code) . "', `data` = '" . $db->escape($json) . "', `saved_at` = NOW()");
			} catch (\Throwable $e) {
				// A safety net: its failure must not break the screen.
			}
		}

		private static function restore($db, string $code, bool $all): void {
			try {
				self::ensure($db);

				$query = $db->query("SELECT `data` FROM `" . DB_PREFIX . self::TABLE . "` WHERE `code` = '" . $db->escape($code) . "' LIMIT 1");

				if (!$query->num_rows) {
					return;
				}

				$rows = json_decode((string)$query->row['data'], true);

				if (!is_array($rows)) {
					return;
				}

				$have = $all ? [] : array_column(self::rows($db, $code), 'key', 'key');

				foreach ($rows as $row) {
					if (!isset($row['key'], $row['value']) || strpos((string)$row['key'], $code . '_') !== 0 || isset($have[(string)$row['key']])) {
						continue;
					}

					$key = $db->escape((string)$row['key']);

					$db->query("DELETE FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0' AND `code` = '" . $db->escape($code) . "' AND `key` = '" . $key . "'");
					$db->query("INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = '0', `code` = '" . $db->escape($code) . "', `key` = '" . $key . "', `value` = '" . $db->escape((string)$row['value']) . "', `serialized` = '" . (int)($row['serialized'] ?? 0) . "'");
				}
			} catch (\Throwable $e) {
				// Nothing more can be done here; the module starts with its defaults.
			}
		}

		private static function rows($db, string $code): array {
			$query = $db->query("SELECT `key`, `value`, `serialized` FROM `" . DB_PREFIX . "setting` WHERE `store_id` = '0' AND `code` = '" . $db->escape($code) . "'");

			$rows = [];

			foreach ($query->rows as $row) {
				$rows[] = [
					'key'        => (string)$row['key'],
					'value'      => (string)$row['value'],
					'serialized' => (int)$row['serialized'],
				];
			}

			return $rows;
		}
	}
}

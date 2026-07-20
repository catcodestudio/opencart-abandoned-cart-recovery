<?php
namespace Opencart\System\Library\CcAbandonedCart;

/**
 * Data access layer for the abandoned cart table.
 *
 * Instantiated with the OpenCart DB object: new Repository($this->db).
 *
 * ⚠ On the PDO driver $this->db->escape() does NOT return an escaped string —
 * it returns a placeholder token that is bound when the query runs. Gluing
 * literal characters onto it (the classic LIKE '"..escape($s).."%') produces a
 * broken statement. Every LIKE pattern below is therefore assembled first and
 * escaped as one complete value.
 */
class Repository {

	public const STATUS_ACTIVE    = 'active';
	public const STATUS_ABANDONED = 'abandoned';
	public const STATUS_RECOVERED = 'recovered';
	public const STATUS_LOST      = 'lost';

	private $db;

	public function __construct($db) {
		$this->db = $db;
	}

	public static function table(): string {
		return DB_PREFIX . 'abandoned_cart';
	}

	/** @return string[] */
	public static function statuses(): array {
		return [self::STATUS_ACTIVE, self::STATUS_ABANDONED, self::STATUS_RECOVERED, self::STATUS_LOST];
	}

	/* ---------------------------------------------------------------- write */

	/**
	 * Insert or refresh the single row belonging to one shopper session.
	 *
	 * @return int Row id, 0 on failure.
	 */
	public function upsert(array $data): int {
		$sessionKey = (string)($data['session_key'] ?? '');
		if ($sessionKey === '') {
			return 0;
		}

		$existing = $this->findBySession($sessionKey);

		$email = (string)($data['email'] ?? '');
		$name  = (string)($data['customer_name'] ?? '');

		if ($existing) {
			// Never overwrite a known e-mail or name with an empty one.
			if ($email === '') {
				$email = (string)$existing['email'];
			}
			if ($name === '') {
				$name = (string)$existing['customer_name'];
			}

			$sets = [
				"`customer_id` = " . (int)($data['customer_id'] ?? 0),
				"`email` = '" . $this->db->escape($email) . "'",
				"`customer_name` = '" . $this->db->escape($name) . "'",
				"`cart_contents` = '" . $this->db->escape((string)($data['cart_contents'] ?? '')) . "'",
				"`cart_total` = " . (float)($data['cart_total'] ?? 0),
				"`currency_code` = '" . $this->db->escape((string)($data['currency_code'] ?? '')) . "'",
				"`language_id` = " . (int)($data['language_id'] ?? 0),
				"`store_id` = " . (int)($data['store_id'] ?? 0),
				"`item_count` = " . (int)($data['item_count'] ?? 0),
				"`updated_at` = NOW()",
			];

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
			`customer_id` = " . (int)($data['customer_id'] ?? 0) . ",
			`email` = '" . $this->db->escape($email) . "',
			`customer_name` = '" . $this->db->escape($name) . "',
			`cart_contents` = '" . $this->db->escape((string)($data['cart_contents'] ?? '')) . "',
			`cart_total` = " . (float)($data['cart_total'] ?? 0) . ",
			`currency_code` = '" . $this->db->escape((string)($data['currency_code'] ?? '')) . "',
			`language_id` = " . (int)($data['language_id'] ?? 0) . ",
			`store_id` = " . (int)($data['store_id'] ?? 0) . ",
			`item_count` = " . (int)($data['item_count'] ?? 0) . ",
			`status` = '" . self::STATUS_ACTIVE . "',
			`created_at` = NOW(),
			`updated_at` = NOW()");

		return (int)$this->db->getLastId();
	}

	/**
	 * Update a whitelisted set of columns.
	 *
	 * @param array<string,mixed> $fields Column => value. NULL writes SQL NULL.
	 */
	public function update(int $id, array $fields): void {
		$strings = ['email', 'customer_name', 'cart_contents', 'currency_code', 'status', 'token_hash', 'coupon_code'];
		$ints    = ['customer_id', 'item_count', 'emails_sent', 'coupon_id', 'recovered_order_id', 'language_id', 'store_id'];
		$floats  = ['cart_total', 'recovered_total'];
		$dates   = ['token_expires_at', 'last_email_at', 'abandoned_at', 'recovered_at'];

		$sets = ['`updated_at` = NOW()'];

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

	public function delete(int $id): void {
		$this->db->query("DELETE FROM `" . self::table() . "` WHERE `abandoned_cart_id` = " . $id);
	}

	/* ----------------------------------------------------------------- read */

	public function findBySession(string $sessionKey): ?array {
		$row = $this->db->query("SELECT * FROM `" . self::table() . "` WHERE `session_key` = '" . $this->db->escape($sessionKey) . "' LIMIT 1")->row;

		return $row ?: null;
	}

	public function find(int $id): ?array {
		$row = $this->db->query("SELECT * FROM `" . self::table() . "` WHERE `abandoned_cart_id` = " . $id . " LIMIT 1")->row;

		return $row ?: null;
	}

	public function findByTokenHash(string $hash): ?array {
		if ($hash === '') {
			return null;
		}
		$row = $this->db->query("SELECT * FROM `" . self::table() . "` WHERE `token_hash` = '" . $this->db->escape($hash) . "' LIMIT 1")->row;

		return $row ?: null;
	}

	public function findByCouponCode(string $code): ?array {
		if ($code === '') {
			return null;
		}
		$row = $this->db->query("SELECT * FROM `" . self::table() . "` WHERE `coupon_code` = '" . $this->db->escape($code) . "' LIMIT 1")->row;

		return $row ?: null;
	}

	/**
	 * Active carts idle for longer than $minutes — the scan promotes them to
	 * "abandoned". Rows without an e-mail address can never be recovered, so
	 * they are skipped.
	 */
	public function staleActive(int $minutes, int $limit = 200): array {
		return $this->db->query("SELECT * FROM `" . self::table() . "`
			WHERE `status` = '" . self::STATUS_ACTIVE . "'
			  AND `email` <> ''
			  AND `updated_at` < DATE_SUB(NOW(), INTERVAL " . max(1, $minutes) . " MINUTE)
			ORDER BY `updated_at` ASC LIMIT " . max(1, $limit))->rows;
	}

	/** Abandoned carts still eligible for another reminder. */
	public function pendingReminders(int $maxStep, int $limit = 50): array {
		return $this->db->query("SELECT * FROM `" . self::table() . "`
			WHERE `status` = '" . self::STATUS_ABANDONED . "'
			  AND `email` <> ''
			  AND `emails_sent` < " . max(1, $maxStep) . "
			ORDER BY `abandoned_at` ASC LIMIT " . max(1, $limit))->rows;
	}

	/**
	 * Throttle: has this address already been mailed by us within $days days?
	 */
	public function emailedRecently(string $email, int $days, int $excludeId = 0): bool {
		if ($days < 1 || $email === '') {
			return false;
		}
		$row = $this->db->query("SELECT `abandoned_cart_id` FROM `" . self::table() . "`
			WHERE `email` = '" . $this->db->escape($email) . "'
			  AND `abandoned_cart_id` <> " . $excludeId . "
			  AND `last_email_at` IS NOT NULL
			  AND `last_email_at` > DATE_SUB(NOW(), INTERVAL " . $days . " DAY)
			LIMIT 1")->row;

		return !empty($row);
	}

	/**
	 * Open carts belonging to one buyer — flipped to "recovered" once an order
	 * with the same e-mail or customer id arrives.
	 */
	public function openForCustomer(string $email, int $customerId): array {
		$where = [];
		if ($email !== '') {
			$where[] = "`email` = '" . $this->db->escape($email) . "'";
		}
		if ($customerId > 0) {
			$where[] = "`customer_id` = " . $customerId;
		}
		if (!$where) {
			return [];
		}

		return $this->db->query("SELECT * FROM `" . self::table() . "`
			WHERE `status` IN ('" . self::STATUS_ACTIVE . "', '" . self::STATUS_ABANDONED . "')
			  AND (" . implode(' OR ', $where) . ")
			LIMIT 50")->rows;
	}

	/**
	 * Paged listing for the admin table.
	 *
	 * @return array{items:array,total:int}
	 */
	public function paged(string $status, string $search, int $perPage, int $page, string $sort, string $order): array {
		$allowedSort = ['created_at', 'updated_at', 'cart_total', 'email', 'status'];
		if (!in_array($sort, $allowedSort, true)) {
			$sort = 'updated_at';
		}
		$order   = (strtolower($order) === 'asc') ? 'ASC' : 'DESC';
		$perPage = max(1, $perPage);
		$offset  = (max(1, $page) - 1) * $perPage;

		$where = ['1 = 1'];
		if (in_array($status, self::statuses(), true)) {
			$where[] = "`status` = '" . $this->db->escape($status) . "'";
		}
		if ($search !== '') {
			// ⚠ The whole LIKE pattern — wildcards included — is escaped as one
			// value; on PDO escape() yields a bound placeholder and appending
			// '%' to it outside the quotes would corrupt the statement.
			$where[] = "`email` LIKE '" . $this->db->escape('%' . $search . '%') . "'";
		}
		$clause = implode(' AND ', $where);

		$items = $this->db->query("SELECT * FROM `" . self::table() . "` WHERE " . $clause . "
			ORDER BY `" . $sort . "` " . $order . " LIMIT " . (int)$offset . ", " . (int)$perPage)->rows;

		$total = (int)($this->db->query("SELECT COUNT(*) AS total FROM `" . self::table() . "` WHERE " . $clause)->row['total'] ?? 0);

		return ['items' => $items, 'total' => $total];
	}

	/** Every row, oldest first — used by the CSV export. */
	public function allForExport(string $status): array {
		$where = '1 = 1';
		if (in_array($status, self::statuses(), true)) {
			$where = "`status` = '" . $this->db->escape($status) . "'";
		}

		return $this->db->query("SELECT * FROM `" . self::table() . "` WHERE " . $where . " ORDER BY `abandoned_cart_id` ASC")->rows;
	}

	/**
	 * Counts per status plus recovered revenue.
	 *
	 * @return array{abandoned:int,recovered:int,revenue:float,rate:float}
	 */
	public function stats(): array {
		$abandoned = (int)($this->db->query("SELECT COUNT(*) AS total FROM `" . self::table() . "`
			WHERE `status` IN ('" . self::STATUS_ABANDONED . "', '" . self::STATUS_RECOVERED . "', '" . self::STATUS_LOST . "')")->row['total'] ?? 0);

		$recovered = (int)($this->db->query("SELECT COUNT(*) AS total FROM `" . self::table() . "`
			WHERE `status` = '" . self::STATUS_RECOVERED . "'")->row['total'] ?? 0);

		$revenue = (float)($this->db->query("SELECT SUM(`recovered_total`) AS total FROM `" . self::table() . "`
			WHERE `status` = '" . self::STATUS_RECOVERED . "'")->row['total'] ?? 0);

		return [
			'abandoned' => $abandoned,
			'recovered' => $recovered,
			'revenue'   => $revenue,
			'rate'      => $abandoned > 0 ? round(($recovered / $abandoned) * 100, 1) : 0.0,
		];
	}

	/** Abandoned carts whose recovery window has closed become "lost". */
	public function expireStale(int $days): void {
		$this->db->query("UPDATE `" . self::table() . "`
			SET `status` = '" . self::STATUS_LOST . "', `token_hash` = '', `token_expires_at` = NULL, `updated_at` = NOW()
			WHERE `status` = '" . self::STATUS_ABANDONED . "'
			  AND `abandoned_at` IS NOT NULL
			  AND `abandoned_at` < DATE_SUB(NOW(), INTERVAL " . max(1, $days) . " DAY)");
	}

	/** Retention: physically remove rows older than $days days. */
	public function purgeOld(int $days): void {
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
	public function issueToken(int $id, int $lifetimeDays): string {
		$token = bin2hex(random_bytes(16));

		$this->db->query("UPDATE `" . self::table() . "`
			SET `token_hash` = '" . $this->db->escape(hash('sha256', $token)) . "',
			    `token_expires_at` = DATE_ADD(NOW(), INTERVAL " . max(1, $lifetimeDays) . " DAY),
			    `updated_at` = NOW()
			WHERE `abandoned_cart_id` = " . $id);

		return $token;
	}

	/** Burn a token once it has been used (or found expired). */
	public function clearToken(int $id): void {
		$this->db->query("UPDATE `" . self::table() . "`
			SET `token_hash` = '', `token_expires_at` = NULL, `updated_at` = NOW()
			WHERE `abandoned_cart_id` = " . $id);
	}

	/** Create the table. Safe to call repeatedly. */
	public function install(): void {
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

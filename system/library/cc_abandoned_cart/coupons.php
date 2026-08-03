<?php
namespace Opencart\System\Library\CcAbandonedCart;

/**
 * Pro: personal discount coupon attached to the follow-up reminders.
 *
 * A real OpenCart coupon row is created in `oc_coupon` — one per cart, single
 * use, with its own expiry date — and the code is stored back on the cart row.
 * OpenCart coupons have no native e-mail restriction, so the binding to the
 * shopper is enforced by the storefront event
 * (catalog/model/marketing/coupon.getCoupon/after): a coupon issued by this
 * extension is only handed out when the current session belongs to the address
 * it was generated for.
 */
class Coupons {

	private $db;
	private Settings $settings;
	private Repository $repository;

	public function __construct($db, Settings $settings, Repository $repository) {
		$this->db         = $db;
		$this->settings   = $settings;
		$this->repository = $repository;
	}

	/** Should this reminder step carry a coupon? */
	public function appliesToStep(int $step, bool $isPro): bool {
		if (!$isPro || !$this->settings->isOn('coupon_enabled')) {
			return false;
		}

		return $step >= max(1, $this->settings->getInt('coupon_from_email', 2));
	}

	/**
	 * Coupon code for this cart, created on first use and reused afterwards.
	 *
	 * @return string Empty when disabled or on failure.
	 */
	public function codeForCart(array $cart, int $step, bool $isPro): string {
		if (!$this->appliesToStep($step, $isPro)) {
			return '';
		}

		$existing = trim((string)($cart['coupon_code'] ?? ''));
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
			`name` = '" . $this->db->escape('Abandoned cart recovery — ' . $email) . "',
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

		$this->repository->update((int)$cart['abandoned_cart_id'], [
			'coupon_code' => $code,
			'coupon_id'   => $couponId,
		]);

		return $code;
	}

	/**
	 * Is $code one of ours, and does it belong to $email / $customerId?
	 *
	 * Returns true when the coupon is not ours at all (nothing to guard) or
	 * when the shopper matches; false when it belongs to somebody else.
	 */
	public function isAllowedFor(string $code, string $email, int $customerId): bool {
		$row = $this->repository->findByCouponCode($code);
		if (!$row) {
			return true;
		}

		if ($email !== '' && strcasecmp((string)$row['email'], $email) === 0) {
			return true;
		}

		return $customerId > 0 && (int)$row['customer_id'] === $customerId;
	}

	/** Human-readable coupon sentence for the {coupon} placeholder. */
	public function describe(string $code, string $template, string $currencySuffix = ''): string {
		if ($code === '') {
			return '';
		}

		$amount = (float)$this->settings->get('coupon_amount', 10);
		$days   = max(1, $this->settings->getInt('coupon_expiry_days', 7));
		$value  = (string)$this->settings->get('coupon_type', 'P') === 'F'
			? rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.') . ' ' . $currencySuffix
			: rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.') . '%';

		return str_replace(['{value}', '{code}', '{days}'], [trim($value), $code, (string)$days], $template);
	}

	/**
	 * Remove coupons this extension generated for rows that no longer exist.
	 *
	 * ⚠ An orphan is deleted whether or not it has expired. The e-mail binding
	 * lives on the cart row, so a coupon whose row is gone is no longer personal
	 * at all — anybody who was forwarded the code could spend it. Expiry is not
	 * the condition; having no owner is.
	 *
	 * The one-hour floor covers the gap in codeForCart(), where the coupon row
	 * exists for a moment before the code is written back to the cart.
	 */
	public function purgeOrphans(): void {
		$this->db->query("DELETE c FROM `" . DB_PREFIX . "coupon` c
			LEFT JOIN `" . Repository::table() . "` a ON a.`coupon_id` = c.`coupon_id`
			WHERE c.`code` LIKE '" . $this->db->escape('BACK-%') . "'
			  AND a.`abandoned_cart_id` IS NULL
			  AND c.`date_added` < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
	}
}

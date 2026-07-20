<?php
require_once DIR_SYSTEM . 'library/cc_abandoned_cart.php';

/**
 * Scheduled work (OpenCart 3.x).
 *
 * Registered with the OpenCart cron table when the store has one (3.0.3+).
 * Either way a system cron can be pointed straight at:
 *   index.php?route=extension/module/abandoned_cart_cron/scan
 *   index.php?route=extension/module/abandoned_cart_cron/cleanup
 */
class ControllerExtensionModuleAbandonedCartCron extends Controller {

	/** Promote idle carts, then send whatever reminder is due. */
	public function scan() {
		if (!$this->enabled()) {
			return;
		}

		$this->load->language('extension/module/abandoned_cart');

		$settings   = new CcAbandonedCartSettings($this->config);
		$repository = new CcAbandonedCartRepository($this->db);
		$isPro      = CcAbandonedCartLicense::isPro($this->config);

		$this->promoteIdle($settings, $repository, $isPro);
		$this->sendDue($settings, $repository, $isPro);
	}

	/** Close carts past their recovery window, then honour retention. */
	public function cleanup() {
		if (!$this->enabled()) {
			return;
		}

		$settings   = new CcAbandonedCartSettings($this->config);
		$repository = new CcAbandonedCartRepository($this->db);

		$repository->expireStale(max(1, $settings->getInt('token_lifetime', 7)));

		$retention = $settings->getInt('retention_days', 90);
		if ($retention > 0) {
			$repository->purgeOld($retention);
		}

		try {
			$coupons = new CcAbandonedCartCoupons($this->db, $settings, $repository);
			$coupons->purgeOrphans();
		} catch (Exception $e) {
			// Ignore.
		}
	}

	private function enabled() {
		return (string)$this->config->get(CcAbandonedCartSettings::PREFIX . 'status') === '1';
	}

	private function promoteIdle($settings, $repository, $isPro) {
		$minutes  = max(1, $settings->getInt('abandon_after', 60));
		$telegram = new CcAbandonedCartTelegram($settings);
		$notify   = $telegram->isEnabled($isPro);

		foreach ($repository->staleActive($minutes) as $row) {
			$repository->update((int)$row['abandoned_cart_id'], array(
				'status'       => CcAbandonedCartRepository::STATUS_ABANDONED,
				'abandoned_at' => true,
			));

			if (!$notify) {
				continue;
			}

			try {
				$telegram->notifyAbandoned($row, array(
					'title'    => $this->language->get('text_tg_title'),
					'email'    => $this->language->get('text_tg_email'),
					'total'    => $this->language->get('text_tg_total'),
					'customer' => $this->language->get('text_tg_customer'),
				));
			} catch (Exception $e) {
				// A failing notification must not stop the queue.
			}
		}
	}

	private function sendDue($settings, $repository, $isPro) {
		$steps = $settings->emailSteps($isPro);
		if (!$steps) {
			return;
		}

		$byStep = array();
		foreach ($steps as $step) {
			// An empty template falls back to the shop-language default.
			if (trim((string)$step['subject']) === '') {
				$step['subject'] = (string)$this->language->get('text_email_' . $step['step'] . '_subject');
			}
			if (trim((string)$step['body']) === '') {
				$step['body'] = (string)$this->language->get('text_email_' . $step['step'] . '_body');
			}
			$byStep[(int)$step['step']] = $step;
		}

		$maxStep  = max(array_keys($byStep));
		$cooldown = $settings->getInt('email_cooldown', 3);

		$mailer  = new CcAbandonedCartMailer($this->registry, $settings, $repository);
		$coupons = new CcAbandonedCartCoupons($this->db, $settings, $repository);

		$strings = array(
			'fallback_name' => (string)$this->language->get('text_customer_fallback'),
			'item_line'     => (string)$this->language->get('text_item_line'),
		);
		$couponTemplate = (string)$this->language->get('text_coupon_line');
		$currency       = (string)$this->config->get('config_currency');

		foreach ($repository->pendingReminders($maxStep) as $row) {
			$next = (int)$row['emails_sent'] + 1;
			if (!isset($byStep[$next])) {
				continue;
			}
			$step = $byStep[$next];

			// Step 1 is measured from abandonment, later steps from the last send.
			$anchor = ($next === 1)
				? (string)(isset($row['abandoned_at']) ? $row['abandoned_at'] : '')
				: (string)(isset($row['last_email_at']) ? $row['last_email_at'] : '');
			if ($anchor === '') {
				continue;
			}

			$dueAt = strtotime($anchor) + ((int)$step['delay'] * 60);
			if ($dueAt > time()) {
				continue;
			}

			// Throttle: do not pester an address another cart mailed recently.
			if ($next === 1 && $repository->emailedRecently((string)$row['email'], $cooldown, (int)$row['abandoned_cart_id'])) {
				continue;
			}

			try {
				$code = $coupons->codeForCart($row, $next, $isPro);
				if ($code !== '') {
					// Re-read so the freshly written coupon code is on the row.
					$row['coupon_code'] = $code;
				}
				$text = $coupons->describe($code, $couponTemplate, $currency);

				$mailer->send($row, $step, $strings, $code, $text);
			} catch (Exception $e) {
				// Move on to the next cart.
			}
		}
	}
}

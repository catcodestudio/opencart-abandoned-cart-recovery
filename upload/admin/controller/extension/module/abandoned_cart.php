<?php
require_once DIR_SYSTEM . 'library/cc_abandoned_cart.php';

/**
 * Abandoned Cart Recovery — admin controller (OpenCart 3.x, no namespaces).
 *
 * Routes are slash-separated in OpenCart 3, so the sub-actions are
 * extension/module/abandoned_cart/save, /carts and /export.
 */
class ControllerExtensionModuleAbandonedCart extends Controller {

	private $route = 'extension/module/abandoned_cart';

	const PER_PAGE = 20;

	/* --------------------------------------------------------------- settings */

	public function index() {
		$this->load->language($this->route);
		$this->document->setTitle($this->language->get('heading_title'));

		$token = 'user_token=' . $this->session->data['user_token'];

		CcAbandonedCartCrypto::resetFailures();
		$settings = new CcAbandonedCartSettings($this->config);
		$all      = $settings->all();
		$broken   = CcAbandonedCartCrypto::failures() > 0;

		$data = array();

		$data['breadcrumbs'] = array(
			array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $token, true)),
			array('text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', $token . '&type=module', true)),
			array('text' => $this->language->get('heading_title'), 'href' => $this->url->link($this->route, $token, true)),
		);

		$data['save']           = $this->url->link($this->route . '/save', $token, true);
		$data['back']           = $this->url->link('marketplace/extension', $token . '&type=module', true);
		$data['carts']          = $this->url->link($this->route . '/carts', $token, true);
		$data['connect_bot']    = $this->url->link($this->route . '/connectbot', $token, true);
		$data['disconnect_bot'] = $this->url->link($this->route . '/disconnectbot', $token, true);
		$data['user_token']     = $this->session->data['user_token'];

		foreach (CcAbandonedCartSettings::defaults() as $key => $default) {
			$data[$key] = $all[$key];
		}

		// Secrets are never echoed back into the form; a blank field on save
		// keeps whatever is already stored.
		$data['telegram_bot_token_set'] = $all['telegram_bot_token'] !== '' ? 1 : 0;
		$data['license_key_set']        = $all['license_key'] !== '' ? 1 : 0;
		$data['telegram_bot_token']     = '';
		$data['license_key']            = '';

		// The webhook secret is a credential too: only its presence is exposed,
		// never the value itself.
		$data['telegram_webhook_set']    = trim((string)$all['telegram_webhook_secret']) !== '' ? 1 : 0;
		$data['telegram_webhook_secret'] = '';

		// A stored secret that will not decrypt means the shop moved directory
		// or database — say exactly that instead of "the field is empty".
		$data['crypto_broken'] = $broken;

		// An empty template falls back to the shop-language default, so show
		// that default in the form instead of an empty box.
		foreach (array(1, 2, 3) as $n) {
			if (trim((string)$data['email_' . $n . '_subject']) === '') {
				$data['email_' . $n . '_subject'] = (string)$this->language->get('text_email_' . $n . '_subject');
			}
			if (trim((string)$data['email_' . $n . '_body']) === '') {
				$data['email_' . $n . '_body'] = (string)$this->language->get('text_email_' . $n . '_body');
			}
		}

		$data['is_pro']          = CcAbandonedCartLicense::isPro($this->config);
		$data['has_license']     = CcAbandonedCartLicense::hasLicense($this->config);
		$data['trial_active']    = CcAbandonedCartLicense::trialActive($this->config);
		$data['trial_days_left'] = CcAbandonedCartLicense::trialDaysLeft($this->config);

		$data['cron_url'] = (defined('HTTP_CATALOG') ? HTTP_CATALOG : '') . 'index.php?route=extension/module/abandoned_cart_cron/scan';

		$data['header']      = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer']      = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view($this->route, $data));
	}

	public function save() {
		$this->load->language($this->route);
		$json = array();

		if (!$this->user->hasPermission('modify', $this->route)) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$json) {
			$post     = $this->request->post;
			$settings = new CcAbandonedCartSettings($this->config);
			$isPro    = CcAbandonedCartLicense::isPro($this->config);
			$current  = $settings->all();

			$values = array();

			// Always editable.
			$values['status']         = !empty($post['status']) ? '1' : '0';
			$values['abandon_after']  = (string)max(1, (int)(isset($post['abandon_after']) ? $post['abandon_after'] : 60));
			$values['token_lifetime'] = (string)max(1, (int)(isset($post['token_lifetime']) ? $post['token_lifetime'] : 7));
			$values['email_cooldown'] = (string)max(0, (int)(isset($post['email_cooldown']) ? $post['email_cooldown'] : 3));
			$values['retention_days'] = (string)max(0, (int)(isset($post['retention_days']) ? $post['retention_days'] : 90));

			// Reminder 1 is always editable; 2 and 3 only while Pro is unlocked,
			// so a lapsed licence can never have its stored chain wiped.
			foreach (array(1, 2, 3) as $n) {
				if ($n > 1 && !$isPro) {
					$values['email_' . $n . '_enabled'] = (string)$current['email_' . $n . '_enabled'];
					$values['email_' . $n . '_delay']   = (string)$current['email_' . $n . '_delay'];
					$values['email_' . $n . '_subject'] = (string)$current['email_' . $n . '_subject'];
					$values['email_' . $n . '_body']    = (string)$current['email_' . $n . '_body'];
					continue;
				}

				if ($n > 1) {
					$values['email_' . $n . '_enabled'] = !empty($post['email_' . $n . '_enabled']) ? '1' : '0';
				}
				$values['email_' . $n . '_delay']   = (string)max(1, (int)(isset($post['email_' . $n . '_delay']) ? $post['email_' . $n . '_delay'] : 60));
				$values['email_' . $n . '_subject'] = trim((string)(isset($post['email_' . $n . '_subject']) ? $post['email_' . $n . '_subject'] : ''));
				$values['email_' . $n . '_body']    = trim((string)(isset($post['email_' . $n . '_body']) ? $post['email_' . $n . '_body'] : ''));
			}

			if ($isPro) {
				$type = (string)(isset($post['coupon_type']) ? $post['coupon_type'] : 'P');

				$values['coupon_enabled']     = !empty($post['coupon_enabled']) ? '1' : '0';
				$values['coupon_type']        = $type === 'F' ? 'F' : 'P';
				$values['coupon_amount']      = (string)max(0, (float)(isset($post['coupon_amount']) ? $post['coupon_amount'] : 10));
				$values['coupon_from_email']  = (string)min(3, max(1, (int)(isset($post['coupon_from_email']) ? $post['coupon_from_email'] : 2)));
				$values['coupon_expiry_days'] = (string)max(1, (int)(isset($post['coupon_expiry_days']) ? $post['coupon_expiry_days'] : 7));

				$values['telegram_enabled'] = !empty($post['telegram_enabled']) ? '1' : '0';
				// The webhook may have stored a chat id after this page was rendered,
				// so a form that still carries an empty field must never wipe it.
				$postedChat = trim((string)(isset($post['telegram_chat_id']) ? $post['telegram_chat_id'] : ''));
				$values['telegram_chat_id'] = ($postedChat === '' && trim((string)$current['telegram_chat_id']) !== '')
					? (string)$current['telegram_chat_id']
					: $postedChat;
			} else {
				foreach (array('coupon_enabled', 'coupon_type', 'coupon_amount', 'coupon_from_email', 'coupon_expiry_days', 'telegram_enabled', 'telegram_chat_id') as $key) {
					$values[$key] = (string)$current[$key];
				}
			}

			$data = array();
			foreach ($values as $key => $value) {
				$data[CcAbandonedCartSettings::PREFIX . $key] = $value;
			}

			// Secrets: a blank field keeps the stored (already encrypted) value;
			// a filled one is encrypted before it is written.
			foreach (CcAbandonedCartSettings::secretKeys() as $secret) {
				$key    = CcAbandonedCartSettings::PREFIX . $secret;
				$posted = trim((string)(isset($post[$secret]) ? $post[$secret] : ''));

				if ($secret === 'telegram_bot_token' && !$isPro) {
					$data[$key] = (string)$this->config->get($key);
					continue;
				}

				$data[$key] = $posted !== ''
					? CcAbandonedCartCrypto::encrypt($posted)
					: (string)$this->config->get($key);
			}

			// ⚠ editSetting() deletes every row of this code first, so any key
			// that is not part of the payload — the Pro trial start — would be
			// wiped on save and the trial would restart forever. Carry it over.
			$started = (int)$this->config->get(CcAbandonedCartSettings::PREFIX . 'trial_started');
			$data[CcAbandonedCartSettings::PREFIX . 'trial_started'] = (string)($started > 0 ? $started : time());

			// Same trap for the webhook secret: it is written by "Connect bot",
			// never by this form, so without carrying it over an ordinary save
			// would silently unregister the webhook handler.
			$data[CcAbandonedCartSettings::PREFIX . 'telegram_webhook_secret'] = (string)$this->config->get(CcAbandonedCartSettings::PREFIX . 'telegram_webhook_secret');

			$this->load->model('setting/setting');
			$this->model_setting_setting->editSetting(CcAbandonedCartSettings::CODE, $data);

			$json['success'] = $this->language->get('text_success');
		}

		$this->jsonResponse($json);
	}

	/* --------------------------------------------------------- Pro: Telegram */

	/**
	 * Register the Telegram webhook so the bot reports its own chat id.
	 *
	 * Takes the token from the form when the shop owner has just typed it (the
	 * field is write-only, so it is normally blank) and falls back to the stored
	 * one. A freshly typed token is persisted right away — otherwise the webhook
	 * would point at a storefront that cannot answer.
	 */
	public function connectbot() {
		$this->load->language($this->route);
		$json = array();

		if (!$this->user->hasPermission('modify', $this->route)) {
			$json['error'] = $this->language->get('error_permission');
		} elseif (!CcAbandonedCartLicense::isPro($this->config)) {
			$json['error'] = $this->language->get('error_pro');
		}

		if (!$json) {
			$settings = new CcAbandonedCartSettings($this->config);
			$posted   = trim((string)(isset($this->request->post['telegram_bot_token']) ? $this->request->post['telegram_bot_token'] : ''));
			$token    = $posted !== '' ? $posted : trim((string)$settings->get('telegram_bot_token', ''));

			if ($token === '') {
				$json['error'] = $this->language->get('error_telegram_token');
			} else {
				if ($posted !== '') {
					CcAbandonedCartSettings::writeValue($this->db, 'telegram_bot_token', CcAbandonedCartCrypto::encrypt($posted));
				}

				$secret = CcAbandonedCartTelegram::newSecret();
				$result = CcAbandonedCartTelegram::setWebhook($token, $this->webhookUrl(), $secret);

				if (!empty($result['ok'])) {
					CcAbandonedCartSettings::writeValue($this->db, 'telegram_webhook_secret', $secret);
					$json['success']   = $this->language->get('text_webhook_connected');
					$json['connected'] = 1;
				} else {
					// Surface Telegram's own wording — "Bad Request: bad webhook:
					// HTTPS url must be provided" is far more useful than "failed".
					$description   = trim((string)(isset($result['description']) ? $result['description'] : ''));
					$json['error'] = $this->language->get('error_telegram_api')
						. ($description !== '' ? ' ' . $description : '');
				}
			}
		}

		$this->jsonResponse($json);
	}

	/** Unregister the webhook and forget the secret, so the route goes deaf. */
	public function disconnectbot() {
		$this->load->language($this->route);
		$json = array();

		if (!$this->user->hasPermission('modify', $this->route)) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$json) {
			$settings = new CcAbandonedCartSettings($this->config);
			$token    = trim((string)$settings->get('telegram_bot_token', ''));

			$description = '';
			if ($token !== '') {
				$result      = CcAbandonedCartTelegram::deleteWebhook($token);
				$description = empty($result['ok']) ? trim((string)(isset($result['description']) ? $result['description'] : '')) : '';
			}

			// Drop the secret regardless: with no secret stored the public route
			// rejects everything, so the shop is safe even if Telegram was
			// unreachable and the webhook is still registered on their side.
			CcAbandonedCartSettings::writeValue($this->db, 'telegram_webhook_secret', '');

			$json['success']   = $this->language->get('text_webhook_disconnected')
				. ($description !== '' ? ' ' . $description : '');
			$json['connected'] = 0;
		}

		$this->jsonResponse($json);
	}

	/** Public storefront URL Telegram will POST every update to. */
	private function webhookUrl() {
		$base = defined('HTTP_CATALOG') ? HTTP_CATALOG : '';

		return $base . 'index.php?route=extension/module/abandoned_cart/telegram';
	}

	/* ------------------------------------------------------------- cart list */

	public function carts() {
		$this->load->language($this->route);
		$this->document->setTitle($this->language->get('heading_carts'));

		$token = 'user_token=' . $this->session->data['user_token'];

		$data = array();

		$data['breadcrumbs'] = array(
			array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $token, true)),
			array('text' => $this->language->get('heading_title'), 'href' => $this->url->link($this->route, $token, true)),
			array('text' => $this->language->get('heading_carts'), 'href' => $this->url->link($this->route . '/carts', $token, true)),
		);

		$data['back']       = $this->url->link($this->route, $token, true);
		$data['export']     = $this->url->link($this->route . '/export', $token, true);
		$data['user_token'] = $this->session->data['user_token'];
		$data['is_pro']     = CcAbandonedCartLicense::isPro($this->config);

		$status = (string)(isset($this->request->get['filter_status']) ? $this->request->get['filter_status'] : '');
		$search = trim((string)(isset($this->request->get['filter_email']) ? $this->request->get['filter_email'] : ''));
		$page   = max(1, (int)(isset($this->request->get['page']) ? $this->request->get['page'] : 1));
		$sort   = (string)(isset($this->request->get['sort']) ? $this->request->get['sort'] : 'updated_at');
		$order  = (string)(isset($this->request->get['order']) ? $this->request->get['order'] : 'DESC');

		$repository = new CcAbandonedCartRepository($this->db);
		$result     = $repository->paged($status, $search, self::PER_PAGE, $page, $sort, $order);
		$stats      = $repository->stats();

		$labels = array(
			CcAbandonedCartRepository::STATUS_ACTIVE    => $this->language->get('text_status_active'),
			CcAbandonedCartRepository::STATUS_ABANDONED => $this->language->get('text_status_abandoned'),
			CcAbandonedCartRepository::STATUS_RECOVERED => $this->language->get('text_status_recovered'),
			CcAbandonedCartRepository::STATUS_LOST      => $this->language->get('text_status_lost'),
		);

		$data['filter_status'] = $status;
		$data['filter_email']  = $search;
		$data['sort']          = $sort;
		$data['order']         = $order;

		$data['status_filters'] = array(
			array('value' => '', 'text' => $this->language->get('text_status_all')),
		);
		foreach (CcAbandonedCartRepository::statuses() as $slug) {
			$data['status_filters'][] = array('value' => $slug, 'text' => $labels[$slug]);
		}

		$data['carts'] = array();
		foreach ($result['items'] as $row) {
			$items = json_decode((string)$row['cart_contents'], true);
			$lines = array();
			if (is_array($items)) {
				foreach (array_slice($items, 0, 5) as $item) {
					if (!isset($item['name'])) {
						continue;
					}
					$lines[] = (string)$item['name'] . ' × ' . (int)(isset($item['quantity']) ? $item['quantity'] : 1);
				}
				if (count($items) > 5) {
					$lines[] = sprintf($this->language->get('text_more_items'), count($items) - 5);
				}
			}

			$currencyCode = (string)$row['currency_code'] !== '' ? (string)$row['currency_code'] : (string)$this->config->get('config_currency');

			$data['carts'][] = array(
				'abandoned_cart_id'  => (int)$row['abandoned_cart_id'],
				'email'              => (string)$row['email'],
				'customer_name'      => (string)$row['customer_name'],
				'registered'         => (int)$row['customer_id'] > 0,
				'items'              => $lines,
				'item_count'         => (int)$row['item_count'],
				'cart_total'         => $this->currency->format((float)$row['cart_total'], $currencyCode),
				'status'             => (string)$row['status'],
				'status_text'        => isset($labels[(string)$row['status']]) ? $labels[(string)$row['status']] : (string)$row['status'],
				'emails_sent'        => (int)$row['emails_sent'],
				'last_email_at'      => (string)(isset($row['last_email_at']) ? $row['last_email_at'] : ''),
				'coupon_code'        => (string)$row['coupon_code'],
				'created_at'         => (string)$row['created_at'],
				'abandoned_at'       => (string)(isset($row['abandoned_at']) ? $row['abandoned_at'] : ''),
				'recovered_order_id' => (int)$row['recovered_order_id'],
				'order_link'         => (int)$row['recovered_order_id'] > 0
					? $this->url->link('sale/order/info', $token . '&order_id=' . (int)$row['recovered_order_id'], true)
					: '',
			);
		}

		$data['stats'] = array(
			'abandoned' => (int)$stats['abandoned'],
			'recovered' => (int)$stats['recovered'],
			'revenue'   => $this->currency->format((float)$stats['revenue'], (string)$this->config->get('config_currency')),
			'rate'      => (float)$stats['rate'] . '%',
		);

		$filterQuery = '';
		if ($status !== '') {
			$filterQuery .= '&filter_status=' . urlencode($status);
		}
		if ($search !== '') {
			$filterQuery .= '&filter_email=' . urlencode($search);
		}

		// OpenCart 3 renders pagination through the library, not a controller.
		$pagination        = new Pagination();
		$pagination->total = $result['total'];
		$pagination->page  = $page;
		$pagination->limit = self::PER_PAGE;
		$pagination->url   = $this->url->link($this->route . '/carts', $token . $filterQuery . '&page={page}', true);

		$data['pagination'] = $pagination->render();
		$data['results']    = sprintf($this->language->get('text_pagination'), $result['total']);

		$data['header']      = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer']      = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/module/abandoned_cart_list', $data));
	}

	/* ------------------------------------------------------------ Pro: export */

	public function export() {
		$this->load->language($this->route);

		if (!$this->user->hasPermission('access', $this->route) || !CcAbandonedCartLicense::isPro($this->config)) {
			$this->response->setOutput($this->language->get('error_pro'));

			return;
		}

		$status = (string)(isset($this->request->get['filter_status']) ? $this->request->get['filter_status'] : '');
		$repo   = new CcAbandonedCartRepository($this->db);
		$rows   = $repo->allForExport($status);

		$columns = array(
			'abandoned_cart_id', 'email', 'customer_name', 'customer_id', 'status', 'items', 'item_count',
			'cart_total', 'currency_code', 'emails_sent', 'coupon_code', 'recovered_order_id',
			'recovered_total', 'created_at', 'abandoned_at', 'recovered_at',
		);

		$handle = fopen('php://temp', 'r+');
		if ($handle === false) {
			$this->response->setOutput($this->language->get('error_export'));

			return;
		}

		fputcsv($handle, $columns);

		foreach ($rows as $row) {
			$names = array();
			$items = json_decode((string)$row['cart_contents'], true);
			if (is_array($items)) {
				foreach ($items as $item) {
					if (isset($item['name'])) {
						$names[] = (string)$item['name'] . ' x' . (int)(isset($item['quantity']) ? $item['quantity'] : 1);
					}
				}
			}

			$line = array();
			foreach ($columns as $column) {
				$line[] = $column === 'items' ? implode('; ', $names) : (string)(isset($row[$column]) ? $row[$column] : '');
			}
			fputcsv($handle, $line);
		}

		rewind($handle);
		$csv = (string)stream_get_contents($handle);
		fclose($handle);

		$this->response->addHeader('Content-Type: text/csv; charset=utf-8');
		$this->response->addHeader('Content-Disposition: attachment; filename="abandoned-carts-' . date('Y-m-d') . '.csv"');
		// BOM so Excel opens UTF-8 correctly.
		$this->response->setOutput("\xEF\xBB\xBF" . $csv);
	}

	/* ------------------------------------------------------- install/uninstall */

	public function install() {
		$repository = new CcAbandonedCartRepository($this->db);
		$repository->install();

		// ⚠ Start the 7-day Pro free trial. In OpenCart 3 the settings model
		// exposes editSettingValue(), which only runs an UPDATE — on a fresh
		// install the row does not exist yet, so the write would be silently
		// lost and Pro would stay unlocked forever. Insert the row directly.
		$exists = $this->db->query("SELECT `setting_id` FROM `" . DB_PREFIX . "setting`
			WHERE `store_id` = '0' AND `code` = '" . CcAbandonedCartSettings::CODE . "' AND `key` = '" . CcAbandonedCartSettings::PREFIX . "trial_started' LIMIT 1");

		if (!$exists->num_rows) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "setting`
				SET `store_id` = '0',
				    `code` = '" . CcAbandonedCartSettings::CODE . "',
				    `key` = '" . CcAbandonedCartSettings::PREFIX . "trial_started',
				    `value` = '" . (int)time() . "',
				    `serialized` = '0'");
		}

		$this->load->model('setting/event');

		// ⚠ OpenCart 3 addEvent() is POSITIONAL — addEvent($code, $trigger,
		// $action, $status, $sort_order). The associative-array form is the
		// OpenCart 4 signature and fatals here. Triggers are slash-separated,
		// and the order history model method is addOrderHistory (OpenCart 4
		// renamed it to addHistory).
		$events = array(
			array('abandoned_cart_cart_add',      'catalog/controller/checkout/cart/add/after',      'extension/module/abandoned_cart_events/cartChanged'),
			array('abandoned_cart_cart_edit',     'catalog/controller/checkout/cart/edit/after',     'extension/module/abandoned_cart_events/cartChanged'),
			array('abandoned_cart_cart_remove',   'catalog/controller/checkout/cart/remove/after',   'extension/module/abandoned_cart_events/cartChanged'),
			array('abandoned_cart_register',      'catalog/controller/checkout/register/save/after', 'extension/module/abandoned_cart_events/registerSaved'),
			array('abandoned_cart_guest',         'catalog/controller/checkout/guest/save/after',    'extension/module/abandoned_cart_events/registerSaved'),
			array('abandoned_cart_order_history', 'catalog/model/checkout/order/addOrderHistory/after', 'extension/module/abandoned_cart_events/orderHistoryAdded'),
			array('abandoned_cart_coupon_guard',  'catalog/model/marketing/coupon/getCoupon/after',  'extension/module/abandoned_cart_events/couponGuard'),
			array('abandoned_cart_footer',        'catalog/view/common/footer/after',                'extension/module/abandoned_cart_events/injectCapture'),
		);

		foreach ($events as $event) {
			try {
				$this->model_setting_event->deleteEventByCode($event[0]);
			} catch (Exception $e) {
				// Not present yet.
			}

			$this->model_setting_event->addEvent($event[0], $event[1], $event[2], 1, 10);
		}

		// OpenCart 3 ships an `oc_cron` table only from 3.0.3.x; register there
		// when it exists so the merchant does not have to add a system cron.
		$this->registerCrons();

		$this->load->model('user/user_group');
		foreach (array('access', 'modify') as $permission) {
			try {
				$this->model_user_user_group->addPermission((int)$this->user->getGroupId(), $permission, $this->route);
			} catch (Exception $e) {
				// Permissions can also be granted from System → Users → Groups.
			}
		}
	}

	public function uninstall() {
		$this->load->model('setting/event');
		foreach (array(
			'abandoned_cart_cart_add', 'abandoned_cart_cart_edit', 'abandoned_cart_cart_remove',
			'abandoned_cart_register', 'abandoned_cart_guest', 'abandoned_cart_order_history',
			'abandoned_cart_coupon_guard', 'abandoned_cart_footer',
		) as $code) {
			try {
				$this->model_setting_event->deleteEventByCode($code);
			} catch (Exception $e) {
				// Already gone.
			}
		}

		if ($this->cronTableExists()) {
			$this->db->query("DELETE FROM `" . DB_PREFIX . "cron` WHERE `code` IN ('abandoned_cart_scan', 'abandoned_cart_cleanup')");
		}

		// The cart table is intentionally preserved so a reinstall keeps the
		// captured history and the recovery statistics.
	}

	/* ----------------------------------------------------------------- helpers */

	private function cronTableExists() {
		try {
			$result = $this->db->query("SHOW TABLES LIKE '" . DB_PREFIX . "cron'");

			return $result->num_rows > 0;
		} catch (Exception $e) {
			return false;
		}
	}

	private function registerCrons() {
		if (!$this->cronTableExists()) {
			return;
		}

		$crons = array(
			array('abandoned_cart_scan', 'hour', 'extension/module/abandoned_cart_cron/scan'),
			array('abandoned_cart_cleanup', 'day', 'extension/module/abandoned_cart_cron/cleanup'),
		);

		foreach ($crons as $cron) {
			try {
				$this->db->query("DELETE FROM `" . DB_PREFIX . "cron` WHERE `code` = '" . $this->db->escape($cron[0]) . "'");
				$this->db->query("INSERT INTO `" . DB_PREFIX . "cron`
					SET `code` = '" . $this->db->escape($cron[0]) . "',
					    `cycle` = '" . $this->db->escape($cron[1]) . "',
					    `action` = '" . $this->db->escape($cron[2]) . "',
					    `status` = '1',
					    `date_added` = NOW(),
					    `date_modified` = NOW()");
			} catch (Exception $e) {
				// Older 3.0.x builds have a different cron schema — the system
				// cron URL shown in the settings screen still works.
			}
		}
	}

	private function jsonResponse(array $data) {
		if (ob_get_level() > 0) {
			ob_clean();
		}
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));
	}
}

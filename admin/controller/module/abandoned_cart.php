<?php
namespace Opencart\Admin\Controller\Extension\AbandonedCart\Module;

require_once DIR_EXTENSION . 'abandoned_cart/system/library/cc_abandoned_cart/crypto.php';
require_once DIR_EXTENSION . 'abandoned_cart/system/library/cc_abandoned_cart/settings.php';
require_once DIR_EXTENSION . 'abandoned_cart/system/library/cc_abandoned_cart/license.php';
require_once DIR_EXTENSION . 'abandoned_cart/system/library/cc_abandoned_cart/repository.php';
require_once DIR_EXTENSION . 'abandoned_cart/system/library/cc_abandoned_cart/telegram.php';

use Opencart\System\Library\CcAbandonedCart\Crypto;
use Opencart\System\Library\CcAbandonedCart\Settings;
use Opencart\System\Library\CcAbandonedCart\License;
use Opencart\System\Library\CcAbandonedCart\Repository;
use Opencart\System\Library\CcAbandonedCart\Telegram;

/**
 * Abandoned Cart Recovery — admin controller.
 */
class AbandonedCart extends \Opencart\System\Engine\Controller {

	private string $route = 'extension/abandoned_cart/module/abandoned_cart';

	private const PER_PAGE = 20;

	/* --------------------------------------------------------------- settings */

	public function index(): void {
		$this->load->language($this->route);
		$this->document->setTitle($this->language->get('heading_title'));

		$token = 'user_token=' . $this->session->data['user_token'];

		Crypto::resetFailures();
		$settings = new Settings($this->config);
		$all      = $settings->all();
		$broken   = Crypto::failures() > 0;

		$data = [];

		$data['breadcrumbs'] = [
			['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $token)],
			['text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', $token . '&type=module')],
			['text' => $this->language->get('heading_title'), 'href' => $this->url->link($this->route, $token)],
		];

		$data['save']           = $this->url->link($this->route . '.save', $token);
		$data['back']           = $this->url->link('marketplace/extension', $token . '&type=module');
		$data['carts']          = $this->url->link($this->route . '.carts', $token);
		$data['connect_bot']    = $this->url->link($this->route . '.connectBot', $token);
		$data['disconnect_bot'] = $this->url->link($this->route . '.disconnectBot', $token);
		$data['user_token']     = $this->session->data['user_token'];

		foreach (Settings::defaults() as $key => $default) {
			$data[$key] = $all[$key];
		}

		// Secrets are never echoed back into the form; a blank field on save
		// keeps whatever is already stored.
		$data['telegram_bot_token_set'] = $all['telegram_bot_token'] !== '' ? 1 : 0;
		$data['telegram_bot_token']     = '';

		// The webhook secret is a credential too: only its presence is exposed,
		// never the value itself.
		$data['telegram_webhook_set']    = trim((string)$all['telegram_webhook_secret']) !== '' ? 1 : 0;
		$data['telegram_webhook_secret'] = '';

		// A stored secret that will not decrypt means the shop moved directory
		// or database — say exactly that instead of "the field is empty".
		$data['crypto_broken'] = $broken;

		// An empty template falls back to the shop-language default, so show
		// that default in the form instead of an empty box.
		foreach ([1, 2, 3] as $n) {
			if (trim((string)$data['email_' . $n . '_subject']) === '') {
				$data['email_' . $n . '_subject'] = (string)$this->language->get('text_email_' . $n . '_subject');
			}
			if (trim((string)$data['email_' . $n . '_body']) === '') {
				$data['email_' . $n . '_body'] = (string)$this->language->get('text_email_' . $n . '_body');
			}
		}

		// A stored key is re-verified in the background at most once a day; an
		// unreachable server is a no-op thanks to the grace window in isPro().
		License::maybeVerify($this->registry);

		$data['is_pro']           = License::isPro($this->registry);
		$data['has_license']      = License::hasKey($this->registry);
		$data['trial_active']     = License::trialActive($this->registry);
		$data['trial_available']  = License::trialAvailable($this->registry);
		$data['trial_days_left']  = License::trialDaysLeft($this->registry);
		$data['license_expires']  = License::expiresAt($this->registry);
		$data['notice_dismissed'] = (int)$this->config->get(Settings::PREFIX . 'notice_dismissed') === 1;

		$data['license_trial']      = $this->url->link($this->route . '.licenseTrial', $token);
		$data['license_activate']   = $this->url->link($this->route . '.licenseActivate', $token);
		$data['license_deactivate'] = $this->url->link($this->route . '.licenseDeactivate', $token);
		$data['dismiss_notice']     = $this->url->link($this->route . '.dismissNotice', $token);

		$data['cron_url'] = (defined('HTTP_CATALOG') ? HTTP_CATALOG : '') . 'index.php?route=extension/abandoned_cart/cron.scan';

		$data['header']      = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer']      = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view($this->route, $data));
	}

	public function save(): void {
		$this->load->language($this->route);
		$json = [];

		if (!$this->user->hasPermission('modify', $this->route)) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$json) {
			$post     = $this->request->post;
			$settings = new Settings($this->config);
			$isPro    = License::isPro($this->registry);
			$current  = $settings->all();

			$values = [];

			// Always editable.
			$values['status']         = !empty($post['status']) ? '1' : '0';
			$values['abandon_after']  = (string)max(1, (int)($post['abandon_after'] ?? 60));
			$values['token_lifetime'] = (string)max(1, (int)($post['token_lifetime'] ?? 7));
			$values['email_cooldown'] = (string)max(0, (int)($post['email_cooldown'] ?? 3));
			$values['retention_days'] = (string)max(0, (int)($post['retention_days'] ?? 90));

			// Reminder 1 is always editable; 2 and 3 only while Pro is unlocked,
			// so a lapsed licence can never have its stored chain wiped.
			foreach ([1, 2, 3] as $n) {
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
				$values['email_' . $n . '_delay']   = (string)max(1, (int)($post['email_' . $n . '_delay'] ?? 60));
				$values['email_' . $n . '_subject'] = trim((string)($post['email_' . $n . '_subject'] ?? ''));
				$values['email_' . $n . '_body']    = trim((string)($post['email_' . $n . '_body'] ?? ''));
			}

			if ($isPro) {
				$type = (string)($post['coupon_type'] ?? 'P');

				$values['coupon_enabled']     = !empty($post['coupon_enabled']) ? '1' : '0';
				$values['coupon_type']        = $type === 'F' ? 'F' : 'P';
				$values['coupon_amount']      = (string)max(0, (float)($post['coupon_amount'] ?? 10));
				$values['coupon_from_email']  = (string)min(3, max(1, (int)($post['coupon_from_email'] ?? 2)));
				$values['coupon_expiry_days'] = (string)max(1, (int)($post['coupon_expiry_days'] ?? 7));

				$values['telegram_enabled'] = !empty($post['telegram_enabled']) ? '1' : '0';
				// The webhook may have stored a chat id after this page was rendered,
				// so a form that still carries an empty field must never wipe it.
				$postedChat = trim((string)($post['telegram_chat_id'] ?? ''));
				$values['telegram_chat_id'] = ($postedChat === '' && trim((string)$current['telegram_chat_id']) !== '')
					? (string)$current['telegram_chat_id']
					: $postedChat;
			} else {
				foreach (['coupon_enabled', 'coupon_type', 'coupon_amount', 'coupon_from_email', 'coupon_expiry_days', 'telegram_enabled', 'telegram_chat_id'] as $key) {
					$values[$key] = (string)$current[$key];
				}
			}

			$data = [];
			foreach ($values as $key => $value) {
				$data[Settings::PREFIX . $key] = $value;
			}

			// Secrets: a blank field keeps the stored (already encrypted) value;
			// a filled one is encrypted before it is written.
			foreach (Settings::SECRET_KEYS as $secret) {
				$key    = Settings::PREFIX . $secret;
				$posted = trim((string)($post[$secret] ?? ''));

				if ($secret === 'telegram_bot_token' && !$isPro) {
					$data[$key] = (string)$this->config->get($key);
					continue;
				}

				$data[$key] = $posted !== ''
					? Crypto::encrypt($posted)
					: (string)$this->config->get($key);
			}

			// ⚠ editSetting() deletes every row of this code first, so any key
			// the form does not post would be wiped on save. The licence state is
			// written by the licence client and by the dismiss button, never by
			// this form — carry it over verbatim, otherwise every "Save" would
			// deactivate a paid licence. Saving must NOT start a trial either.
			foreach (License::serverOwned() as $key) {
				$data[$key] = (string)$this->config->get($key);
			}

			// Same trap for the webhook secret: it is written by "Connect bot",
			// never by this form, so without carrying it over an ordinary save
			// would silently unregister the webhook handler.
			$data[Settings::PREFIX . 'telegram_webhook_secret'] = (string)$this->config->get(Settings::PREFIX . 'telegram_webhook_secret');

			$this->load->model('setting/setting');
			$this->model_setting_setting->editSetting(Settings::CODE, $data);

			$json['success'] = $this->language->get('text_success');
		}

		$this->jsonResponse($json);
	}

	/* ------------------------------------------------------------- licensing */

	/**
	 * "Try 7 days" — mint a real licence key for this shop and activate it.
	 *
	 * The key is shown right in the modal and duplicated by e-mail, so the
	 * merchant ends up with exactly what a buyer gets, only shorter-lived.
	 */
	public function licenseTrial(): void {
		$this->load->language($this->route);
		$json = [];

		if (!$this->user->hasPermission('modify', $this->route)) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$json) {
			$email  = trim((string)($this->request->post['email'] ?? ''));
			$result = License::startTrial($this->registry, $email);

			if (!empty($result['ok'])) {
				$json['success']    = $this->language->get('text_trial_started');
				$json['key']        = (string)($result['key'] ?? '');
				$json['expires_at'] = (string)($result['expires_at'] ?? '');
			} else {
				$json['error'] = $this->licenseError($result);
			}
		}

		$this->jsonResponse($json);
	}

	/** Activate a key the merchant pasted after buying on catcode.com.ua. */
	public function licenseActivate(): void {
		$this->load->language($this->route);
		$json = [];

		if (!$this->user->hasPermission('modify', $this->route)) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$json) {
			$key = trim((string)($this->request->post['license_key'] ?? ''));

			if (!License::keyLooksValid($key)) {
				$json['error'] = $this->language->get('error_license_format');
			} else {
				$result = License::activate($this->registry, $key);

				if (!empty($result['ok'])) {
					$json['success']    = $this->language->get('text_license_activated');
					$json['expires_at'] = License::expiresAt($this->registry);
				} else {
					$json['error'] = $this->licenseError($result);
				}
			}
		}

		$this->jsonResponse($json);
	}

	/** Release the activation slot so the key can be moved to another shop. */
	public function licenseDeactivate(): void {
		$this->load->language($this->route);
		$json = [];

		if (!$this->user->hasPermission('modify', $this->route)) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$json) {
			License::deactivate($this->registry);
			$json['success'] = $this->language->get('text_license_deactivated');
		}

		$this->jsonResponse($json);
	}

	/** Hide the Pro notice for good — one dismissal, never shown again. */
	public function dismissNotice(): void {
		$this->load->language($this->route);
		$json = [];

		if (!$this->user->hasPermission('modify', $this->route)) {
			$json['error'] = $this->language->get('error_permission');
		} else {
			Settings::writeValue($this->db, 'notice_dismissed', '1');
			$json['success'] = 1;
		}

		$this->jsonResponse($json);
	}

	/**
	 * Turn a licence-server verdict into a sentence the shop owner can act on.
	 * An unknown code falls back to the generic message plus the raw code, so a
	 * new server-side error is still diagnosable from the screen.
	 */
	private function licenseError(array $result): string {
		$code = (string)($result['error'] ?? '');

		$known = [
			'bad_email'       => 'error_license_email',
			'missing_key'     => 'error_license_format',
			'invalid_key'     => 'error_license_invalid',
			'expired'         => 'error_license_expired',
			'limit_reached'   => 'error_license_limit',
			'wrong_product'   => 'error_license_product',
			'trial_used_site' => 'error_trial_used',
			'trial_used'      => 'error_trial_used',
			'network'         => 'error_license_network',
			'no_curl'         => 'error_license_network',
			'bad_response'    => 'error_license_network',
		];

		if (isset($known[$code])) {
			return $this->language->get($known[$code]);
		}

		$message = trim((string)($result['message'] ?? ''));

		return $this->language->get('error_license_generic')
			. ($code !== '' ? ' (' . $code . ')' : '')
			. ($message !== '' ? ' ' . $message : '');
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
	public function connectBot(): void {
		$this->load->language($this->route);
		$json = [];

		if (!$this->user->hasPermission('modify', $this->route)) {
			$json['error'] = $this->language->get('error_permission');
		} elseif (!License::isPro($this->registry)) {
			$json['error'] = $this->language->get('error_pro');
		}

		if (!$json) {
			$settings = new Settings($this->config);
			$posted   = trim((string)($this->request->post['telegram_bot_token'] ?? ''));
			$token    = $posted !== '' ? $posted : trim((string)$settings->get('telegram_bot_token', ''));

			if ($token === '') {
				$json['error'] = $this->language->get('error_telegram_token');
			} else {
				if ($posted !== '') {
					Settings::writeValue($this->db, 'telegram_bot_token', Crypto::encrypt($posted));
				}

				$secret = Telegram::newSecret();
				$url    = $this->webhookUrl();

				$result = Telegram::setWebhook($token, $url, $secret);

				if (!empty($result['ok'])) {
					Settings::writeValue($this->db, 'telegram_webhook_secret', $secret);
					$json['success']  = $this->language->get('text_webhook_connected');
					$json['connected'] = 1;
				} else {
					// Surface Telegram's own wording — "Bad Request: bad webhook:
					// HTTPS url must be provided" is far more useful than "failed".
					$description     = trim((string)($result['description'] ?? ''));
					$json['error']   = $this->language->get('error_telegram_api')
						. ($description !== '' ? ' ' . $description : '');
				}
			}
		}

		$this->jsonResponse($json);
	}

	/** Unregister the webhook and forget the secret, so the route goes deaf. */
	public function disconnectBot(): void {
		$this->load->language($this->route);
		$json = [];

		if (!$this->user->hasPermission('modify', $this->route)) {
			$json['error'] = $this->language->get('error_permission');
		}

		if (!$json) {
			$settings = new Settings($this->config);
			$token    = trim((string)$settings->get('telegram_bot_token', ''));

			$description = '';
			if ($token !== '') {
				$result      = Telegram::deleteWebhook($token);
				$description = empty($result['ok']) ? trim((string)($result['description'] ?? '')) : '';
			}

			// Drop the secret regardless: with no secret stored the public route
			// rejects everything, so the shop is safe even if Telegram was
			// unreachable and the webhook is still registered on their side.
			Settings::writeValue($this->db, 'telegram_webhook_secret', '');

			$json['success']   = $this->language->get('text_webhook_disconnected')
				. ($description !== '' ? ' ' . $description : '');
			$json['connected'] = 0;
		}

		$this->jsonResponse($json);
	}

	/** Public storefront URL Telegram will POST every update to. */
	private function webhookUrl(): string {
		$base = defined('HTTP_CATALOG') ? HTTP_CATALOG : '';

		return $base . 'index.php?route=extension/abandoned_cart/abandoned_cart.telegram';
	}

	/* ------------------------------------------------------------- cart list */

	public function carts(): void {
		$this->load->language($this->route);
		$this->document->setTitle($this->language->get('heading_carts'));

		$token = 'user_token=' . $this->session->data['user_token'];

		$data = [];

		$data['breadcrumbs'] = [
			['text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $token)],
			['text' => $this->language->get('heading_title'), 'href' => $this->url->link($this->route, $token)],
			['text' => $this->language->get('heading_carts'), 'href' => $this->url->link($this->route . '.carts', $token)],
		];

		$data['back']       = $this->url->link($this->route, $token);
		$data['export']     = $this->url->link($this->route . '.export', $token);
		$data['list_url']   = $this->url->link($this->route . '.carts', $token);
		$data['user_token'] = $this->session->data['user_token'];
		$data['is_pro']     = License::isPro($this->registry);

		$status = (string)($this->request->get['filter_status'] ?? '');
		$search = trim((string)($this->request->get['filter_email'] ?? ''));
		$page   = max(1, (int)($this->request->get['page'] ?? 1));
		$sort   = (string)($this->request->get['sort'] ?? 'updated_at');
		$order  = (string)($this->request->get['order'] ?? 'DESC');

		$repository = new Repository($this->db);
		$result     = $repository->paged($status, $search, self::PER_PAGE, $page, $sort, $order);
		$stats      = $repository->stats();

		$labels = [
			Repository::STATUS_ACTIVE    => $this->language->get('text_status_active'),
			Repository::STATUS_ABANDONED => $this->language->get('text_status_abandoned'),
			Repository::STATUS_RECOVERED => $this->language->get('text_status_recovered'),
			Repository::STATUS_LOST      => $this->language->get('text_status_lost'),
		];

		$data['filter_status'] = $status;
		$data['filter_email']  = $search;
		$data['sort']          = $sort;
		$data['order']         = $order;

		$data['status_filters'] = [
			['value' => '', 'text' => $this->language->get('text_status_all')],
		];
		foreach (Repository::statuses() as $slug) {
			$data['status_filters'][] = ['value' => $slug, 'text' => $labels[$slug]];
		}

		$data['carts'] = [];
		foreach ($result['items'] as $row) {
			$items = json_decode((string)$row['cart_contents'], true);
			$lines = [];
			if (is_array($items)) {
				foreach (array_slice($items, 0, 5) as $item) {
					if (!isset($item['name'])) {
						continue;
					}
					$lines[] = (string)$item['name'] . ' × ' . (int)($item['quantity'] ?? 1);
				}
				if (count($items) > 5) {
					$lines[] = sprintf($this->language->get('text_more_items'), count($items) - 5);
				}
			}

			$data['carts'][] = [
				'abandoned_cart_id'  => (int)$row['abandoned_cart_id'],
				'email'              => (string)$row['email'],
				'customer_name'      => (string)$row['customer_name'],
				'registered'         => (int)$row['customer_id'] > 0,
				'items'              => $lines,
				'item_count'         => (int)$row['item_count'],
				'cart_total'         => $this->currency->format((float)$row['cart_total'], (string)$row['currency_code'] ?: $this->config->get('config_currency')),
				'status'             => (string)$row['status'],
				'status_text'        => $labels[(string)$row['status']] ?? (string)$row['status'],
				'emails_sent'        => (int)$row['emails_sent'],
				'last_email_at'      => (string)($row['last_email_at'] ?? ''),
				'coupon_code'        => (string)$row['coupon_code'],
				'created_at'         => (string)$row['created_at'],
				'abandoned_at'       => (string)($row['abandoned_at'] ?? ''),
				'recovered_order_id' => (int)$row['recovered_order_id'],
				'order_link'         => (int)$row['recovered_order_id'] > 0
					? $this->url->link('sale/order.info', $token . '&order_id=' . (int)$row['recovered_order_id'])
					: '',
			];
		}

		$data['stats'] = [
			'abandoned' => (int)$stats['abandoned'],
			'recovered' => (int)$stats['recovered'],
			'revenue'   => $this->currency->format((float)$stats['revenue'], (string)$this->config->get('config_currency')),
			'rate'      => (float)$stats['rate'] . '%',
		];

		$filterQuery = '';
		if ($status !== '') {
			$filterQuery .= '&filter_status=' . urlencode($status);
		}
		if ($search !== '') {
			$filterQuery .= '&filter_email=' . urlencode($search);
		}

		$data['pagination'] = $this->load->controller('common/pagination', [
			'total' => $result['total'],
			'page'  => $page,
			'limit' => self::PER_PAGE,
			'url'   => $this->url->link($this->route . '.carts', $token . $filterQuery . '&page={page}'),
		]);

		$data['results'] = sprintf($this->language->get('text_pagination'), $result['total']);

		$data['header']      = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer']      = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/abandoned_cart/module/abandoned_cart_list', $data));
	}

	/* ------------------------------------------------------------ Pro: export */

	public function export(): void {
		$this->load->language($this->route);

		if (!$this->user->hasPermission('access', $this->route) || !License::isPro($this->registry)) {
			$this->response->setOutput($this->language->get('error_pro'));

			return;
		}

		$status = (string)($this->request->get['filter_status'] ?? '');
		$rows   = (new Repository($this->db))->allForExport($status);

		$columns = [
			'abandoned_cart_id', 'email', 'customer_name', 'customer_id', 'status', 'items', 'item_count',
			'cart_total', 'currency_code', 'emails_sent', 'coupon_code', 'recovered_order_id',
			'recovered_total', 'created_at', 'abandoned_at', 'recovered_at',
		];

		$handle = fopen('php://temp', 'r+');
		if ($handle === false) {
			$this->response->setOutput($this->language->get('error_export'));

			return;
		}

		fputcsv($handle, $columns);

		foreach ($rows as $row) {
			$names = [];
			$items = json_decode((string)$row['cart_contents'], true);
			if (is_array($items)) {
				foreach ($items as $item) {
					if (isset($item['name'])) {
						$names[] = (string)$item['name'] . ' x' . (int)($item['quantity'] ?? 1);
					}
				}
			}

			$line = [];
			foreach ($columns as $column) {
				$line[] = $column === 'items' ? implode('; ', $names) : (string)($row[$column] ?? '');
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

	public function install(): void {
		(new Repository($this->db))->install();

		// ⚠ Installing enables nothing. Earlier builds started the 7-day Pro
		// trial right here, so a merchant who only wanted the free reminder got
		// features he never asked for and lost them a week later. The trial is
		// now offered as a button and issued as a real licence key.

		$this->load->model('setting/event');

		// ⚠ OpenCart 4 addEvent() takes a single associative array; the OC3
		// positional signature fatals here.
		//
		// ⚠ Model events are named DIFFERENTLY across 4.0 and 4.1, and picking
		// either literal separator silently kills the feature on the other:
		//   4.0.2 Loader::model → $route . '/' . $method  → checkout/order/addHistory
		//   4.1.0 Loader::model → $route . '.' . $method  → checkout/order.addHistory
		// Event::trigger() turns `*` into `.*`, so a star matches both. It is the
		// only spelling that works on every 4.x — and nothing warns you when the
		// trigger matches nothing: install() succeeds, the row sits in `oc_event`
		// with status=1, and the handler simply never runs.
		// Controllers are unaffected: there the method is part of the route itself
		// (`checkout/cart.add`), so the dot stays.
		$events = [
			['abandoned_cart_cart_add',      'catalog/controller/checkout/cart.add/after',            'extension/abandoned_cart/events.cartChanged'],
			['abandoned_cart_cart_edit',     'catalog/controller/checkout/cart.edit/after',           'extension/abandoned_cart/events.cartChanged'],
			['abandoned_cart_cart_remove',   'catalog/controller/checkout/cart.remove/after',         'extension/abandoned_cart/events.cartChanged'],
			['abandoned_cart_register',      'catalog/controller/checkout/register.save/after',       'extension/abandoned_cart/events.registerSaved'],
			['abandoned_cart_order_history', 'catalog/model/checkout/order*addHistory/after',         'extension/abandoned_cart/events.orderHistoryAdded'],
			['abandoned_cart_coupon_guard',  'catalog/model/marketing/coupon*getCoupon/after',        'extension/abandoned_cart/events.couponGuard'],
			['abandoned_cart_footer',        'catalog/view/common/footer/after',                      'extension/abandoned_cart/events.injectCapture'],
		];

		foreach ($events as $event) {
			try {
				$this->model_setting_event->deleteEventByCode($event[0]);
			} catch (\Throwable $e) {
				// Not present yet.
			}

			$this->model_setting_event->addEvent([
				'code'        => $event[0],
				'description' => 'Abandoned Cart Recovery',
				'trigger'     => $event[1],
				'action'      => $event[2],
				'status'      => 1,
				'sort_order'  => 10,
			]);
		}

		$this->load->model('setting/cron');
		foreach (['abandoned_cart_scan', 'abandoned_cart_cleanup'] as $code) {
			try {
				$this->model_setting_cron->deleteCronByCode($code);
			} catch (\Throwable $e) {
				// Not present yet.
			}
		}
		$this->model_setting_cron->addCron('abandoned_cart_scan', 'Abandoned Cart Recovery — scan idle carts and send reminders', 'hour', 'extension/abandoned_cart/cron.scan', true);
		$this->model_setting_cron->addCron('abandoned_cart_cleanup', 'Abandoned Cart Recovery — expire links and purge old rows', 'day', 'extension/abandoned_cart/cron.cleanup', true);

		$this->load->model('user/user_group');
		foreach (['access', 'modify'] as $permission) {
			try {
				$this->model_user_user_group->addPermission((int)$this->user->getGroupId(), $permission, $this->route);
			} catch (\Throwable $e) {
				// Permissions can also be granted from Extensions → Modules.
			}
		}
	}

	public function uninstall(): void {
		$this->load->model('setting/event');
		foreach ([
			'abandoned_cart_cart_add', 'abandoned_cart_cart_edit', 'abandoned_cart_cart_remove',
			'abandoned_cart_register', 'abandoned_cart_order_history', 'abandoned_cart_coupon_guard',
			'abandoned_cart_footer',
		] as $code) {
			try {
				$this->model_setting_event->deleteEventByCode($code);
			} catch (\Throwable $e) {
				// Already gone.
			}
		}

		$this->load->model('setting/cron');
		foreach (['abandoned_cart_scan', 'abandoned_cart_cleanup'] as $code) {
			try {
				$this->model_setting_cron->deleteCronByCode($code);
			} catch (\Throwable $e) {
				// Already gone.
			}
		}

		// The cart table is intentionally preserved so a reinstall keeps the
		// captured history and the recovery statistics.
	}

	private function jsonResponse(array $data): void {
		if (ob_get_level() > 0) {
			ob_clean();
		}
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput((string)json_encode($data));
	}
}

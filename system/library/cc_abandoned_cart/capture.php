<?php
namespace Opencart\System\Library\CcAbandonedCart;

/**
 * Cart capture.
 *
 * Logged-in shoppers are recorded as soon as they have something in the cart.
 * Guests are recorded only once they have handed over an e-mail address —
 * either through the checkout register step or through the small storefront
 * script that posts the typed address to our catalog endpoint. A guest who
 * never gives an address is never written to the database at all.
 */
class Capture {

	/** Session key holding the address a guest typed at checkout. */
	public const SESSION_EMAIL = 'abandoned_cart_email';
	public const SESSION_NAME  = 'abandoned_cart_name';

	private $registry;
	private Settings $settings;
	private Repository $repository;

	public function __construct($registry, Settings $settings, Repository $repository) {
		$this->registry   = $registry;
		$this->settings   = $settings;
		$this->repository = $repository;
	}

	/**
	 * Identifier for the current shopper. Registered customers are keyed by
	 * customer id so the row survives a new session cookie.
	 */
	public function sessionKey(): string {
		$customer = $this->registry->get('customer');
		if ($customer && $customer->isLogged()) {
			return 'customer-' . (int)$customer->getId();
		}

		$session = $this->registry->get('session');
		$id      = $session ? (string)$session->getId() : '';

		return $id === '' ? '' : 'session-' . substr($id, 0, 52);
	}

	/** Remember an address a guest typed, then persist the cart. */
	public function rememberEmail(string $email, string $name = ''): void {
		$email = trim($email);
		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			return;
		}

		$session = $this->registry->get('session');
		if ($session) {
			$session->data[self::SESSION_EMAIL] = $email;
			if ($name !== '') {
				$session->data[self::SESSION_NAME] = $name;
			}
		}

		$this->store();
	}

	/** Forget the captured guest identity (after a completed order). */
	public function forgetEmail(): void {
		$session = $this->registry->get('session');
		if (!$session) {
			return;
		}
		unset($session->data[self::SESSION_EMAIL], $session->data[self::SESSION_NAME]);
	}

	/** The address we currently know for this shopper, '' when unknown. */
	public function currentEmail(): string {
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
		if (!empty($session->data['customer']['email'])) {
			return (string)$session->data['customer']['email'];
		}

		return '';
	}

	private function currentName(): string {
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
		if (!empty($session->data['customer'])) {
			$guest = $session->data['customer'];

			return trim((string)($guest['firstname'] ?? '') . ' ' . (string)($guest['lastname'] ?? ''));
		}

		return '';
	}

	/**
	 * Write the current cart to the table.
	 *
	 * Called from the storefront events after every cart mutation and after the
	 * e-mail address becomes known.
	 */
	public function store(): void {
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
			if ($row && (string)$row['status'] === Repository::STATUS_ACTIVE) {
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

		$this->repository->upsert([
			'session_key'   => $key,
			'customer_id'   => ($customer && $customer->isLogged()) ? (int)$customer->getId() : 0,
			'email'         => $email,
			'customer_name' => $this->currentName(),
			'cart_contents' => (string)json_encode($items, JSON_UNESCAPED_UNICODE),
			'cart_total'    => $total,
			// OpenCart's Cart\Currency has no getCode(): the active code lives in
			// the session (shopper's pick) and falls back to the store default.
			'currency_code' => (string)(($session && !empty($session->data['currency'])) ? $session->data['currency'] : $config->get('config_currency')),
			'language_id'   => (int)$config->get('config_language_id'),
			'store_id'      => (int)$config->get('config_store_id'),
			'item_count'    => $count,
		]);

		if ($session) {
			$session->data['abandoned_cart_touched'] = time();
		}
	}

	/** Drop the row for the current session (order placed from another path). */
	public function dropActive(): void {
		$key = $this->sessionKey();
		if ($key === '') {
			return;
		}
		$row = $this->repository->findBySession($key);
		if ($row && (string)$row['status'] === Repository::STATUS_ACTIVE) {
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
	public function snapshot(): array {
		$cart   = $this->registry->get('cart');
		$tax    = $this->registry->get('tax');
		$config = $this->registry->get('config');

		$withTax = (bool)$config->get('config_tax');
		$out     = [];

		foreach ($cart->getProducts() as $product) {
			$quantity   = (int)($product['quantity'] ?? 1);
			$netUnit    = (float)($product['price'] ?? 0);
			$taxClassId = (int)($product['tax_class_id'] ?? ($product['tax'] ?? 0));

			$grossUnit = ($tax && $taxClassId > 0)
				? (float)$tax->calculate($netUnit, $taxClassId, $withTax)
				: $netUnit;

			$out[] = [
				'product_id'           => (int)($product['product_id'] ?? 0),
				'name'                 => (string)($product['name'] ?? ''),
				'model'                => (string)($product['model'] ?? ''),
				'quantity'             => $quantity,
				'option'               => $this->normaliseOptions($product['option'] ?? []),
				'subscription_plan_id' => (int)($product['subscription_plan_id'] ?? 0),
				'price'                => round($grossUnit, 4),
				'line_total'           => round($grossUnit * $quantity, 4),
			];
		}

		return $out;
	}

	/**
	 * Turn the resolved option list returned by Cart::getProducts() back into
	 * the raw {product_option_id: value} shape Cart::add() expects.
	 */
	private function normaliseOptions($options): array {
		if (!is_array($options)) {
			return [];
		}

		$out = [];
		foreach ($options as $option) {
			$id   = (int)($option['product_option_id'] ?? 0);
			$type = (string)($option['type'] ?? '');
			if ($id < 1) {
				continue;
			}

			if (in_array($type, ['select', 'radio', 'image'], true)) {
				$out[$id] = (int)($option['product_option_value_id'] ?? 0);
			} elseif ($type === 'checkbox') {
				$out[$id][] = (int)($option['product_option_value_id'] ?? 0);
			} else {
				$out[$id] = (string)($option['value'] ?? '');
			}
		}

		return $out;
	}
}

# Abandoned Cart Recovery for OpenCart

Records carts that never became an order and brings the shopper back with a reminder e-mail and a one-click restore link.

- **`main`** — OpenCart 4.0.2 – 4.1.x (`abandoned_cart.ocmod.zip`)
- **`opencart-3.x`** — OpenCart 3.0.2 – 3.0.5.0 (`abandoned-cart-oc3.ocmod.zip`)

**Status:** v1.0.0 — both builds tested live (OpenCart 4.1.0.3 and 3.0.5.0): capture → cron marks the cart abandoned → reminder e-mail → restore link rebuilds the cart with the same total → admin statistics.

## Features

- **Capture** — logged-in customers immediately; guests as soon as they type an e-mail at checkout. Nothing is stored for anonymous browsing.
- **Abandoned after N minutes** of inactivity (60 by default).
- **Reminder e-mail** with an editable subject and body — `{customer_name}`, `{cart_items}`, `{recovery_link}`, `{store_name}` — sent through OpenCart's own mail engine.
- **One-time restore link**: only the SHA-256 hash of the token is stored, the comparison is constant-time, the token is burned on use.
- **Statuses** active → abandoned → recovered / lost, with recovery detected automatically when an order appears for that e-mail or customer id.
- **Admin screen** with a status filter and four statistics tiles (abandoned, recovered, recovered revenue, recovery rate).
- **Anti-spam** — never mails the same address more often than once every N days.
- **Automatic clean-up** of cart data after a retention period.
- Interface and e-mails in English and Ukrainian.

## Pro

- Chain of up to **three reminder e-mails**, each with its own delay, subject and body.
- **Personal discount coupon** — a real single-use row in OpenCart's coupon table, percentage or fixed, with its own expiry.
- **Telegram notification** the moment a cart is marked abandoned. "Connect bot" registers a Telegram webhook with a `secret_token`; the shop owner sends `/start` and the chat id is saved by itself.
- **CSV export** of the cart list.

All Pro features are unlocked for a 7-day trial after installation; the free tier keeps working afterwards.

## Install

**OpenCart 4.x** — Extensions → Installer → upload the zip; then Extensions → Extensions → Modules → press the green **+** (this creates the table, registers the events and grants access rights), then edit, enable and save. Before the **+** the route answers *Permission denied* — that is standard OpenCart 4 behaviour.

**OpenCart 3.x** — Extensions → Installer → upload the zip; Extensions → Modifications → Refresh; Extensions → Modules → **+** → edit, enable and save.

## Cron

The installer registers `abandoned_cart_scan` (hourly) and `abandoned_cart_cleanup` (daily). OpenCart's own cron only ticks when someone visits the storefront and its smallest cycle is one hour, so for minute-level accuracy point a system cron at the scan URL shown on the settings page, for example every 15 minutes.

## External services

`api.telegram.org`, and only when Telegram notifications are switched on.

## Licence

GPL-2.0-or-later. See https://www.gnu.org/licenses/gpl-2.0.html

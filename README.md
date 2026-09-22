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

## Changelog

### 1.2.2
- The recovery link works when opened from a mail client or webmail. OpenCart 4 sends the session cookie with SameSite=Strict, so a link from another site restored the cart into a session the next page never saw ("cart is empty") and burned the one-time token. The link now opens a short page that re-submits the token from the shop's own origin.
- A mail provider's link scanner fetching the URL no longer burns the token.
- One order recovers one cart: the recovered row is re-attached to the shopper's new session instead of a second row being created, and an order no longer marks every open cart of the buyer as recovered, so "Recovered carts" and "Recovered revenue" count a purchase once. An ordinary purchase that was never abandoned is not reported as recovered.
- Plain-text part of the reminder e-mail: the recovery link no longer contains `&amp;`.
- Cart list: the filter is labelled "Status" instead of "Enabled".

### 1.2.1
- A purchased licence keeps Pro for good; the trial switches off after 7 days.

## Licence

GPL-2.0-or-later. See https://www.gnu.org/licenses/gpl-2.0.html

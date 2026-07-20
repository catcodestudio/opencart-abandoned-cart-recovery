<?php
// Storefront notices
$_['text_cart_restored']   = 'Welcome back — your cart has been restored.';
$_['error_link_invalid']   = 'This recovery link is no longer valid.';
$_['error_link_expired']   = 'This recovery link has expired.';
$_['error_products_gone']  = 'Sorry, the products from that cart are no longer available.';

// E-mail building blocks
$_['text_customer_fallback'] = 'there';
$_['text_item_line']         = '{name} — {qty} pcs — {total}';
$_['text_coupon_line']       = 'Here is {value} off with the code {code} — it is valid for {days} days.';

// Default reminder templates (used when the setting is left empty)
$_['text_email_1_subject'] = 'You left something behind at {store_name}';
$_['text_email_1_body']    = "Hi {customer_name},\n\nYou added a few things to your cart at {store_name} but did not complete the order:\n\n{cart_items}\n\nYour cart is saved — one click takes you straight back to it:\n{recovery_link}\n\n— {store_name}";
$_['text_email_2_subject'] = 'Still thinking it over? Your cart at {store_name} is waiting';
$_['text_email_2_body']    = "Hi {customer_name},\n\nYour cart at {store_name} is still saved. Here is what is in it:\n\n{cart_items}\n\n{coupon}\nPick up where you left off:\n{recovery_link}\n\n— {store_name}";
$_['text_email_3_subject'] = 'Last reminder about your cart at {store_name}';
$_['text_email_3_body']    = "Hi {customer_name},\n\nThis is the last reminder about the cart you left at {store_name}. We are holding it for a little longer:\n\n{cart_items}\n\n{coupon}\nFinish your order here:\n{recovery_link}\n\n— {store_name}";

// Telegram notification labels
$_['text_tg_title']    = 'Abandoned cart';
$_['text_tg_email']    = 'E-mail:';
$_['text_tg_total']    = 'Total:';
$_['text_tg_customer'] = 'Customer:';

// Telegram webhook replies (%s is the chat id)
$_['text_tg_chat_saved'] = 'Your chat_id: %s — it has been saved in the shop settings.';
$_['text_tg_chat_id']    = 'Your chat_id: %s. Another chat is already configured in the shop settings; send /start to switch notifications to this one.';

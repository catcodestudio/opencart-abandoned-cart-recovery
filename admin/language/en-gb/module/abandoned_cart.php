<?php
// Heading
$_['heading_title']          = 'Abandoned Cart Recovery';
$_['heading_carts']          = 'Abandoned carts';

// Text
$_['text_extension']         = 'Extensions';
$_['text_edit']              = 'Abandoned Cart Recovery settings';
$_['text_success']           = 'Settings saved.';
$_['text_pro_only']          = 'This is a Pro feature.';
$_['text_license_active']    = 'Licence active — all Pro features are unlocked.';
$_['text_trial_left']        = 'Free Pro trial: %s days left. When it ends the free tier keeps working and Pro needs a licence.';
$_['text_trial_over']        = 'The free Pro trial has ended — the free tier keeps working. Enter a licence key to unlock the reminder chain, personal coupons, Telegram notifications and CSV export.';
$_['text_secret_stored']     = 'A value is stored — leave the field empty to keep it.';
$_['text_crypto_broken']     = 'The stored keys could not be decrypted — it looks like the shop was moved to another directory or database. Enter the Telegram token and the licence key again and save.';
$_['text_coupon_percent']    = 'Percentage discount';
$_['text_coupon_fixed']      = 'Fixed amount discount';

$_['text_filter']            = 'Filter';
$_['text_registered']        = 'Registered customer';
$_['text_abandoned_at']      = 'abandoned:';
$_['text_no_carts']          = 'No carts captured yet.';
$_['text_more_items']        = '+%d more';
$_['text_pagination']        = 'Total carts: %d';

$_['text_status_all']        = 'All';
$_['text_status_active']     = 'Active';
$_['text_status_abandoned']  = 'Abandoned';
$_['text_status_recovered']  = 'Recovered';
$_['text_status_lost']       = 'Lost';

$_['text_tile_abandoned']    = 'Carts abandoned';
$_['text_tile_recovered']    = 'Carts recovered';
$_['text_tile_revenue']      = 'Revenue recovered';
$_['text_tile_rate']         = 'Recovery rate';

// Default e-mail templates (used when the field is left empty)
$_['text_email_1_subject']   = 'You left something behind at {store_name}';
$_['text_email_1_body']      = "Hi {customer_name},\n\nYou added a few things to your cart at {store_name} but did not complete the order:\n\n{cart_items}\n\nYour cart is saved — one click takes you straight back to it:\n{recovery_link}\n\n— {store_name}";
$_['text_email_2_subject']   = 'Still thinking it over? Your cart at {store_name} is waiting';
$_['text_email_2_body']      = "Hi {customer_name},\n\nYour cart at {store_name} is still saved. Here is what is in it:\n\n{cart_items}\n\n{coupon}\nPick up where you left off:\n{recovery_link}\n\n— {store_name}";
$_['text_email_3_subject']   = 'Last reminder about your cart at {store_name}';
$_['text_email_3_body']      = "Hi {customer_name},\n\nThis is the last reminder about the cart you left at {store_name}. We are holding it for a little longer:\n\n{cart_items}\n\n{coupon}\nFinish your order here:\n{recovery_link}\n\n— {store_name}";

// Tabs
$_['tab_general']            = 'General';
$_['tab_emails']             = 'Reminders';
$_['tab_coupon']             = 'Coupon';
$_['tab_telegram']           = 'Telegram';
$_['tab_license']            = 'Licence';

// Legends
$_['legend_email_1']         = 'Reminder e-mail 1';
$_['legend_email_2']         = 'Reminder e-mail 2';
$_['legend_email_3']         = 'Reminder e-mail 3';

// Entry
$_['entry_status']           = 'Enabled';
$_['entry_abandon_after']    = 'Mark as abandoned after, minutes';
$_['entry_token_lifetime']   = 'Recovery link lifetime, days';
$_['entry_email_cooldown']   = 'Do not e-mail the same address more often than once every, days';
$_['entry_retention_days']   = 'Delete cart data after, days';
$_['entry_cron']             = 'Cron URL';
$_['entry_enabled']          = 'Enabled';
$_['entry_delay_first']      = 'Send after abandonment, minutes';
$_['entry_delay_next']       = 'Send after the previous reminder, minutes';
$_['entry_subject']          = 'Subject';
$_['entry_body']             = 'Message';
$_['entry_coupon_enabled']   = 'Attach a personal coupon';
$_['entry_coupon_type']      = 'Discount type';
$_['entry_coupon_amount']    = 'Discount value';
$_['entry_coupon_from']      = 'Attach starting from reminder';
$_['entry_coupon_expiry']    = 'Coupon valid for, days';
$_['entry_telegram_enabled'] = 'Notify me in Telegram';
$_['entry_telegram_token']   = 'Bot token';
$_['entry_telegram_chat']    = 'Chat ID';
$_['entry_license']          = 'Licence key';
$_['entry_email']            = 'E-mail';

// Help
$_['help_abandon_after']     = 'Minutes of inactivity before a cart is considered abandoned.';
$_['help_token_lifetime']    = 'How long a recovery link stays valid. Afterwards the cart is marked as lost.';
$_['help_email_cooldown']    = 'Protects frequent shoppers from repeated reminders. Set to 0 to disable.';
$_['help_retention_days']    = 'Set to 0 to keep the data indefinitely (not recommended).';
$_['help_cron']              = 'Registered with the OpenCart cron automatically. For minute-level accuracy point a system cron at this URL, for example every 15 minutes.';
$_['help_delay']             = 'The scan runs on the cron schedule, so the real send time is rounded up to the next run.';
$_['help_placeholders']      = 'Placeholders:';
$_['help_coupon_intro']      = 'A single-use OpenCart coupon is generated per cart and is only accepted for the shopper it was issued to. It is inserted through the {coupon} placeholder.';
$_['help_coupon_from']       = 'Reminder number the coupon first appears in (2 by default).';
$_['help_telegram_intro']    = 'Sends you a message the moment a cart is marked as abandoned. This is the only external service the extension contacts.';
$_['help_telegram_token']    = 'Create a bot with @BotFather in Telegram and paste the token here. The token is stored encrypted.';
$_['help_telegram_chat']     = 'Filled in automatically once you send /start to the bot. You may also type it by hand: numeric chat id or @channelname.';
$_['help_license']           = 'The licence key is stored encrypted.';

// Column
$_['column_customer']        = 'Customer';
$_['column_products']        = 'Products';
$_['column_total']           = 'Total';
$_['column_status']          = 'Status';
$_['column_reminders']       = 'Reminders';
$_['column_captured']        = 'Captured';

// Button
$_['button_export']          = 'Export CSV';

// Error
$_['error_permission']       = 'Warning: You do not have permission to modify Abandoned Cart Recovery!';
$_['error_pro']              = 'This is a Pro feature. Enter a licence key to unlock it.';
$_['error_export']           = 'Could not open the output stream.';

// Telegram: connecting the bot through a webhook
$_['entry_telegram_bot']        = 'Bot connection';
$_['button_connect_bot']        = 'Connect bot';
$_['button_disconnect_bot']     = 'Disconnect bot';
$_['text_webhook_off']          = 'The bot is not connected.';
$_['text_webhook_waiting']      = 'The bot is connected. Send it /start in Telegram and the chat_id fills in by itself.';
$_['text_webhook_ready']        = 'The bot is connected and the chat_id has been received.';
$_['text_webhook_connected']    = 'The bot is connected. Now send it /start in Telegram and reload this page.';
$_['text_webhook_disconnected'] = 'The bot has been disconnected.';
$_['help_telegram_webhook']     = 'Paste the token above and press "Connect bot": the extension registers a webhook on your storefront and the bot replies to /start with its own chat_id. Groups work too. The storefront must be served over HTTPS. Note: a webhook and getUpdates are mutually exclusive, so one bot must serve one shop only, otherwise notifications start going missing.';
$_['error_telegram_token']      = 'Enter the bot token first.';
$_['error_telegram_api']        = 'Telegram rejected the request:';

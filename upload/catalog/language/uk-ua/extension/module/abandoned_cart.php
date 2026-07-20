<?php
// Повідомлення у вітрині
$_['text_cart_restored']   = 'Раді бачити знову — ваш кошик відновлено.';
$_['error_link_invalid']   = 'Це посилання на відновлення кошика більше не дійсне.';
$_['error_link_expired']   = 'Термін дії посилання на відновлення кошика минув.';
$_['error_products_gone']  = 'На жаль, товарів із того кошика вже немає в наявності.';

// Складові листа
$_['text_customer_fallback'] = 'шановний покупець';
$_['text_item_line']         = '{name} — {qty} шт. — {total}';
$_['text_coupon_line']       = 'Ваша знижка {value} за кодом {code} — діє {days} дн.';

// Стандартні шаблони нагадувань (використовуються, якщо налаштування порожнє)
$_['text_email_1_subject'] = 'Ви залишили товари в кошику — {store_name}';
$_['text_email_1_body']    = "Вітаємо, {customer_name}!\n\nВи додали товари до кошика в магазині {store_name}, але не завершили замовлення:\n\n{cart_items}\n\nКошик збережено — один клік, і ви повернетесь до нього:\n{recovery_link}\n\n— {store_name}";
$_['text_email_2_subject'] = 'Ваш кошик у {store_name} усе ще чекає';
$_['text_email_2_body']    = "Вітаємо, {customer_name}!\n\nВаш кошик у магазині {store_name} досі збережено. Ось що в ньому:\n\n{cart_items}\n\n{coupon}\nПродовжити з того самого місця:\n{recovery_link}\n\n— {store_name}";
$_['text_email_3_subject'] = 'Останнє нагадування про ваш кошик у {store_name}';
$_['text_email_3_body']    = "Вітаємо, {customer_name}!\n\nЦе останнє нагадування про кошик, який ви залишили в магазині {store_name}. Ми потримаємо його ще трохи:\n\n{cart_items}\n\n{coupon}\nЗавершити замовлення:\n{recovery_link}\n\n— {store_name}";

// Підписи для сповіщення в Telegram
$_['text_tg_title']    = 'Покинутий кошик';
$_['text_tg_email']    = 'E-mail:';
$_['text_tg_total']    = 'Сума:';
$_['text_tg_customer'] = 'Покупець:';

// Відповіді Telegram-вебхука (%s — chat id)
$_['text_tg_chat_saved'] = 'Ваш chat_id: %s, його збережено в налаштуваннях магазину.';
$_['text_tg_chat_id']    = 'Ваш chat_id: %s. У налаштуваннях магазину вже вказано інший чат — надішліть /start, щоб перевести сповіщення сюди.';

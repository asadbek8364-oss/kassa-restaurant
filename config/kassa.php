<?php

return [
    /*
    | Часовой пояс магазина: по нему считаются «сегодня», неделя, месяц и группировка отчётов.
    */
    'timezone' => env('KASSA_TIMEZONE', 'Asia/Tashkent'),

    /*
    | Срок жизни входа сотрудника (дней с последней активности).
    */
    'token_ttl_days' => (int) env('KASSA_TOKEN_TTL_DAYS', 30),

    /*
    | Код установки. Мастер первого запуска создаёт магазин и владельца только с этим кодом,
    | чтобы чужой человек не занял магазин раньше хозяина. В production без кода мастер закрыт.
    */
    'setup_code' => env('KASSA_SETUP_CODE'),

    /*
    | Демо-магазин (kassa:demo, кнопки «Загрузить демо» и «Начать с чистого листа»).
    | Работает только при KASSA_DEMO_ENABLED=true и никогда — при APP_ENV=production.
    */
    'demo_enabled' => (bool) env('KASSA_DEMO_ENABLED', false),
];

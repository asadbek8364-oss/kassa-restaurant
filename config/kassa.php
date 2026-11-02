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
];

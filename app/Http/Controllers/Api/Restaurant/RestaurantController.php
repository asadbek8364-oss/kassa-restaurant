<?php

namespace App\Http\Controllers\Api\Restaurant;

use App\Http\Controllers\Api\Kassa\KassaController;

/**
 * База API ресторана: текущий сотрудник и проверка данных с русскими сообщениями (как в кассе).
 */
abstract class RestaurantController extends KassaController
{
    private const RESTAURANT_ATTRIBUTES = [
        'app' => 'приложение',
        'table_id' => 'столик',
        'items.*.dish_id' => 'блюдо',
        'items.*.comment' => 'комментарий к блюду',
        'comment' => 'комментарий',
        'guests' => 'гостей',
        'item_ids' => 'позиции',
        'print' => 'печать',
        'status' => 'статус',
        'description' => 'состав',
        'price' => 'цена',
        'active' => 'в меню',
        'stop' => 'стоп-лист',
        'sort' => 'порядок',
        'seats' => 'мест',
        'from' => 'начало периода',
        'to' => 'конец периода',
        'cook_minutes' => 'норматив приготовления',
        'receipt_header' => 'шапка чека',
        'receipt_footer' => 'подвал чека',
        'printer_mode' => 'способ печати',
        'printer_host' => 'IP-адрес принтера',
        'printer_port' => 'порт принтера',
        'printer_codepage' => 'кодовая страница',
        'printer_width' => 'ширина ленты',
        'url' => 'адрес онлайн-сервера',
        'token' => 'sync-токен',
        'category_id' => 'категория',
        'filter' => 'фильтр',
        'ticket_id' => 'отправка на кухню',
        'terminal_enabled' => 'терминал',
        'terminal_host' => 'адрес терминала',
        'terminal_port' => 'порт терминала',
        'terminal_test' => 'тестовый режим',
        'result' => 'исход',
    ];

    protected function attributeNames(): array
    {
        return self::RESTAURANT_ATTRIBUTES + parent::attributeNames();
    }
}

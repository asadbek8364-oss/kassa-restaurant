<?php

namespace App\Http\Controllers\Api\Kassa;

use App\Http\Controllers\Controller;
use App\Models\Kassa\Employee;
use Illuminate\Http\Request;

/**
 * Общая база API «Кассы и склада»: текущий сотрудник и проверка данных с русскими сообщениями.
 */
abstract class KassaController extends Controller
{
    private const MESSAGES = [
        'required' => 'Заполните поле «:attribute»',
        'integer' => 'Поле «:attribute» должно быть целым числом',
        'numeric' => 'Поле «:attribute» должно быть числом',
        'string' => 'Поле «:attribute» должно быть текстом',
        'array' => 'Поле «:attribute» должно быть списком',
        'boolean' => 'Поле «:attribute» должно быть да или нет',
        'in' => 'Недопустимое значение поля «:attribute»',
        'exists' => 'Не найдено: «:attribute»',
        'uuid' => 'Неверный идентификатор операции',
        'date' => 'Поле «:attribute» должно быть датой',
        'digits_between' => 'PIN — от :min до :max цифр',
        'min' => [
            'numeric' => '«:attribute» не может быть меньше :min',
            'string' => '«:attribute»: минимум :min символов',
            'array' => 'Добавьте хотя бы одну позицию',
        ],
        'max' => [
            'numeric' => '«:attribute» не может быть больше :max',
            'string' => '«:attribute»: не длиннее :max символов',
            'array' => 'Слишком много позиций: максимум :max',
        ],
    ];

    private const ATTRIBUTES = [
        'items' => 'товары',
        'items.*.product_id' => 'товар',
        'items.*.sale_item_id' => 'позиция чека',
        'items.*.qty' => 'количество',
        'items.*.cost' => 'закупочная цена',
        'counts' => 'пересчёт',
        'counts.*.product_id' => 'товар',
        'counts.*.actual' => 'факт',
        'payment' => 'способ оплаты',
        'received' => 'получено',
        'customer' => 'покупатель',
        'name' => 'название',
        'category' => 'категория',
        'barcode' => 'штрих-код',
        'buy_price' => 'закупочная цена',
        'sell_price' => 'розничная цена',
        'min_stock' => 'минимальный остаток',
        'stock' => 'начальный остаток',
        'supplier' => 'поставщик',
        'sale_id' => 'чек',
        'reason' => 'причина',
        'role' => 'роль',
        'phone' => 'телефон',
        'pin' => 'PIN',
        'address' => 'адрес',
        'employee_id' => 'сотрудник',
        'store_name' => 'название магазина',
        'owner_name' => 'имя владельца',
        'period' => 'период',
        'type' => 'тип операции',
        'sales' => 'чеки',
        'client_uuid' => 'идентификатор чека',
        'store_id' => 'магазин',
        'sold_at' => 'время продажи',
        'client_total' => 'сумма на устройстве',
        'items.*.name' => 'название товара',
        'items.*.price' => 'цена',
        'threshold' => 'порог',
        'dead_days' => 'срок без продаж',
    ];

    protected function me(Request $request): Employee
    {
        return $request->attributes->get('kassa_employee');
    }

    protected function check(Request $request, array $rules): array
    {
        return $this->checkData($request->all(), $rules);
    }

    protected function checkData(array $data, array $rules): array
    {
        return validator($data, $rules, self::MESSAGES, self::ATTRIBUTES)->validate();
    }
}

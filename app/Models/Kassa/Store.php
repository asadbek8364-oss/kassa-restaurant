<?php

namespace App\Models\Kassa;

use App\Support\Kassa\StoreContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Магазин: название, адрес и счётчики номеров документов (у каждого магазина своя нумерация).
 * Товары, документы и продавцы принадлежат магазину; владельцы общие для всех магазинов.
 */
class Store extends Model
{
    /** Сколько магазинов может создать владелец. */
    public const MAX_STORES = 20;

    protected $table = 'kassa_stores';

    protected $fillable = ['name', 'address', 'is_demo', 'next_sale_no', 'next_return_no', 'next_income_no', 'next_audit_no'];

    protected $attributes = [
        'is_demo' => false,
        'next_sale_no' => 1001,
        'next_return_no' => 1,
        'next_income_no' => 1,
        'next_audit_no' => 1,
    ];

    protected $casts = [
        'is_demo' => 'boolean',
        'next_sale_no' => 'integer',
        'next_return_no' => 'integer',
        'next_income_no' => 'integer',
        'next_audit_no' => 'integer',
    ];

    /** Магазин текущего запроса (см. StoreContext). */
    public static function current(): self
    {
        return app(StoreContext::class)->store();
    }

    /**
     * Строка текущего магазина под блокировкой — операции с остатками и нумерация документов идут строго по очереди.
     * Первая команда транзакции — запись: в SQLite она сразу занимает блокировку записи (иначе две кассы
     * могли бы прочитать одно и то же и получить ошибку при записи), в MySQL/PostgreSQL блокирует строку.
     */
    public static function locked(): self
    {
        $id = static::current()->id;
        static::query()->whereKey($id)->update(['updated_at' => now()]);

        return static::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /** Выдаёт следующий номер документа: sale | return | income | audit. */
    public function takeNumber(string $kind): int
    {
        $column = "next_{$kind}_no";
        $number = $this->{$column};
        $this->{$column} = $number + 1;
        $this->save();

        return $number;
    }
}

<?php

namespace App\Models\Kassa;

use Illuminate\Database\Eloquent\Model;

/**
 * Единственная строка с настройками магазина и счётчиками номеров документов.
 */
class Store extends Model
{
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

    public static function current(): self
    {
        return static::query()->orderBy('id')->first()
            ?? static::create(['name' => 'Мой магазин']);
    }

    /** Строка магазина под блокировкой — сериализует нумерацию документов. */
    public static function locked(): self
    {
        static::current();

        return static::query()->orderBy('id')->lockForUpdate()->firstOrFail();
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

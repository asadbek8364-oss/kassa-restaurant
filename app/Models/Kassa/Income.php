<?php

namespace App\Models\Kassa;

use Illuminate\Database\Eloquent\Model;

/** Приход товара от поставщика. */
class Income extends Model
{
    public const OPENING_SUPPLIER = 'Начальный остаток';

    protected $table = 'kassa_incomes';

    protected $fillable = ['number', 'employee_id', 'supplier', 'total', 'created_at', 'updated_at'];

    protected $casts = [
        'number' => 'integer',
        'total' => 'integer',
    ];

    public function items()
    {
        return $this->hasMany(IncomeItem::class, 'income_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}

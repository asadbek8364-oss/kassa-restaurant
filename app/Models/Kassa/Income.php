<?php

namespace App\Models\Kassa;

use App\Models\Kassa\Concerns\BelongsToStore;
use App\Models\Kassa\Concerns\HasSyncUuid;
use Illuminate\Database\Eloquent\Model;

/** Приход товара от поставщика. */
class Income extends Model
{
    use BelongsToStore;
    use HasSyncUuid;

    public const OPENING_SUPPLIER = 'Начальный остаток';

    protected $table = 'kassa_incomes';

    protected $fillable = ['number', 'employee_id', 'supplier', 'total', 'created_at', 'updated_at', 'uuid', 'cloud_synced_at'];

    protected $casts = [
        'cloud_synced_at' => 'datetime',
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

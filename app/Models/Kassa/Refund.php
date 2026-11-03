<?php

namespace App\Models\Kassa;

use App\Models\Kassa\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;

/** Возврат по чеку (таблица kassa_returns). */
class Refund extends Model
{
    use BelongsToStore;

    public const REASONS = ['Брак', 'Не подошёл', 'Передумал', 'Другое'];

    protected $table = 'kassa_returns';

    protected $fillable = ['number', 'sale_id', 'employee_id', 'payment', 'reason', 'total', 'cost_total', 'created_at', 'updated_at'];

    protected $casts = [
        'number' => 'integer',
        'total' => 'integer',
        'cost_total' => 'integer',
    ];

    public function items()
    {
        return $this->hasMany(RefundItem::class, 'return_id');
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}

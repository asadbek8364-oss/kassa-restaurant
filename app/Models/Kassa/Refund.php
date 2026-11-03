<?php

namespace App\Models\Kassa;

use App\Models\Kassa\Concerns\BelongsToStore;
use App\Models\Kassa\Concerns\HasSyncUuid;
use Illuminate\Database\Eloquent\Model;

/** Возврат по чеку (таблица kassa_returns). */
class Refund extends Model
{
    use BelongsToStore;
    use HasSyncUuid;

    public const REASONS = ['Брак', 'Не подошёл', 'Передумал', 'Другое'];

    protected $table = 'kassa_returns';

    protected $fillable = ['number', 'sale_id', 'employee_id', 'payment', 'reason', 'total', 'cost_total', 'created_at', 'updated_at', 'uuid', 'cloud_synced_at'];

    protected $casts = [
        'cloud_synced_at' => 'datetime',
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

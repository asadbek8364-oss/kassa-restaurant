<?php

namespace App\Models\Kassa;

use App\Models\Kassa\Concerns\BelongsToStore;
use App\Models\Kassa\Concerns\HasSyncUuid;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use BelongsToStore;
    use HasSyncUuid;

    public const PAYMENTS = ['cash', 'card', 'qr'];

    protected $table = 'kassa_sales';

    protected $fillable = ['number', 'employee_id', 'customer', 'payment', 'total', 'cost_total', 'received', 'change_amount', 'client_uuid', 'synced_at', 'client_total', 'created_at', 'updated_at', 'uuid', 'cloud_synced_at'];

    protected $casts = [
        'cloud_synced_at' => 'datetime',
        'number' => 'integer',
        'total' => 'integer',
        'cost_total' => 'integer',
        'received' => 'integer',
        'change_amount' => 'integer',
        'client_total' => 'integer',
        'synced_at' => 'datetime',
    ];

    /** Чек пробит без связи и отправлен на сервер позже. */
    public function isOffline(): bool
    {
        return $this->synced_at !== null;
    }

    public function items()
    {
        return $this->hasMany(SaleItem::class, 'sale_id');
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class, 'sale_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}

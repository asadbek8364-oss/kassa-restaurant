<?php

namespace App\Models\Kassa;

use App\Models\Kassa\Concerns\BelongsToStore;
use App\Models\Kassa\Concerns\HasSyncUuid;
use Illuminate\Database\Eloquent\Model;

/** Инвентаризация: сверка фактических остатков с учётом. */
class Audit extends Model
{
    use BelongsToStore;
    use HasSyncUuid;

    protected $table = 'kassa_audits';

    protected $fillable = ['number', 'employee_id', 'created_at', 'updated_at', 'uuid', 'cloud_synced_at'];

    protected $casts = [
        'cloud_synced_at' => 'datetime',
        'number' => 'integer',
    ];

    public function items()
    {
        return $this->hasMany(AuditItem::class, 'audit_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}

<?php

namespace App\Models\Kassa;

use Illuminate\Database\Eloquent\Model;

/** Инвентаризация: сверка фактических остатков с учётом. */
class Audit extends Model
{
    protected $table = 'kassa_audits';

    protected $fillable = ['number', 'employee_id', 'created_at', 'updated_at'];

    protected $casts = [
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

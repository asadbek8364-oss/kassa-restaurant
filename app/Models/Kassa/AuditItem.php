<?php

namespace App\Models\Kassa;

use Illuminate\Database\Eloquent\Model;

class AuditItem extends Model
{
    public $timestamps = false;

    protected $table = 'kassa_audit_items';

    protected $fillable = ['audit_id', 'product_id', 'name', 'expected', 'actual', 'cost'];

    protected $casts = [
        'expected' => 'integer',
        'actual' => 'integer',
        'cost' => 'integer',
    ];
}

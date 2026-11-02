<?php

namespace App\Models\Kassa;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    public const PAYMENTS = ['cash', 'card', 'qr'];

    protected $table = 'kassa_sales';

    protected $fillable = ['number', 'employee_id', 'customer', 'payment', 'total', 'cost_total', 'received', 'change_amount', 'client_uuid', 'created_at', 'updated_at'];

    protected $casts = [
        'number' => 'integer',
        'total' => 'integer',
        'cost_total' => 'integer',
        'received' => 'integer',
        'change_amount' => 'integer',
    ];

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

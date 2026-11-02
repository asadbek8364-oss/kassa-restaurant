<?php

namespace App\Models\Kassa;

use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    public $timestamps = false;

    protected $table = 'kassa_sale_items';

    protected $fillable = ['sale_id', 'product_id', 'name', 'qty', 'price', 'cost'];

    protected $casts = [
        'qty' => 'integer',
        'price' => 'integer',
        'cost' => 'integer',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function refundItems()
    {
        return $this->hasMany(RefundItem::class, 'sale_item_id');
    }
}

<?php

namespace App\Models\Kassa;

use Illuminate\Database\Eloquent\Model;

class RefundItem extends Model
{
    public $timestamps = false;

    protected $table = 'kassa_return_items';

    protected $fillable = ['return_id', 'sale_item_id', 'product_id', 'name', 'qty', 'price', 'cost'];

    protected $casts = [
        'qty' => 'integer',
        'price' => 'integer',
        'cost' => 'integer',
    ];
}

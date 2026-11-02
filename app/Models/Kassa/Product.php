<?php

namespace App\Models\Kassa;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    public const CATEGORIES = ['chargers', 'cables', 'cases', 'screen', 'audio', 'accessories'];

    protected $table = 'kassa_products';

    protected $fillable = ['name', 'category', 'barcode', 'buy_price', 'sell_price', 'stock', 'min_stock'];

    protected $casts = [
        'buy_price' => 'integer',
        'sell_price' => 'integer',
        'stock' => 'integer',
        'min_stock' => 'integer',
    ];

    public function isLow(): bool
    {
        return $this->stock <= $this->min_stock;
    }
}

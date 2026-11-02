<?php

namespace App\Models\Kassa;

use Illuminate\Database\Eloquent\Model;

class IncomeItem extends Model
{
    public $timestamps = false;

    protected $table = 'kassa_income_items';

    protected $fillable = ['income_id', 'product_id', 'name', 'qty', 'cost'];

    protected $casts = [
        'qty' => 'integer',
        'cost' => 'integer',
    ];
}

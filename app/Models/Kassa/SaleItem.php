<?php

namespace App\Models\Kassa;

use App\Models\Kassa\Concerns\BelongsToStore;
use App\Models\Kassa\Concerns\HasSyncUuid;
use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    use BelongsToStore;
    use HasSyncUuid;

    public $timestamps = false;

    protected $table = 'kassa_sale_items';

    protected $fillable = ['sale_id', 'product_id', 'name', 'qty', 'price', 'cost', 'shortage', 'uuid'];

    protected $casts = [
        'qty' => 'integer',
        'price' => 'integer',
        'cost' => 'integer',
        'shortage' => 'integer',
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

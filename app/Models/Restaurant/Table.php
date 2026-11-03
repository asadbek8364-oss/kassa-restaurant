<?php

namespace App\Models\Restaurant;

use App\Models\Kassa\Concerns\BelongsToStore;
use App\Models\Restaurant\Concerns\SyncsFromOnline;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Столик зала. */
class Table extends Model
{
    use BelongsToStore;
    use SoftDeletes;
    use SyncsFromOnline;

    protected $table = 'restaurant_tables';

    protected $fillable = ['name', 'seats', 'active', 'sort'];

    protected $casts = [
        'version' => 'integer',
        'synced_at' => 'datetime',
        'seats' => 'integer',
        'active' => 'boolean',
        'sort' => 'integer',
    ];

    public function syncFields(): array
    {
        return ['name', 'seats', 'active', 'sort', 'deleted_at'];
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'table_id');
    }

    /** Открытый (не оплаченный и не отменённый) заказ столика. */
    public function openOrder(): ?Order
    {
        return Order::where('table_id', $this->id)->whereIn('status', Order::OPEN)->orderBy('id')->first();
    }
}

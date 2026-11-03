<?php

namespace App\Models\Restaurant;

use App\Models\Kassa\Concerns\BelongsToStore;
use App\Models\Restaurant\Concerns\SyncsFromOnline;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Блюдо меню. active — блюдо есть в меню официанта; stop — стоп-лист: временно закончилось,
 * официант видит его, но добавить в заказ не может.
 */
class Dish extends Model
{
    use BelongsToStore;
    use SoftDeletes;
    use SyncsFromOnline;

    protected $table = 'restaurant_dishes';

    protected $fillable = ['category_id', 'name', 'description', 'price', 'active', 'stop', 'sort'];

    protected $casts = [
        'version' => 'integer',
        'synced_at' => 'datetime',
        'category_id' => 'integer',
        'price' => 'integer',
        'active' => 'boolean',
        'stop' => 'boolean',
        'sort' => 'integer',
    ];

    public function syncFields(): array
    {
        return ['category_id', 'name', 'description', 'price', 'active', 'stop', 'sort', 'deleted_at'];
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id')->withTrashed();
    }

    /** Можно ли сейчас заказать. */
    public function orderable(): bool
    {
        return $this->active && ! $this->stop && ! $this->trashed();
    }
}

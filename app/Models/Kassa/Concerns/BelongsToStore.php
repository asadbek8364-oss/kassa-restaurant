<?php

namespace App\Models\Kassa\Concerns;

use App\Models\Kassa\Scopes\StoreScope;
use App\Models\Kassa\Store;
use App\Support\Kassa\StoreContext;

/**
 * Строка принадлежит магазину: читается только в своём магазине и при создании
 * получает магазин текущего запроса.
 */
trait BelongsToStore
{
    public static function bootBelongsToStore(): void
    {
        static::addGlobalScope(new StoreScope);
        static::creating(function ($model) {
            if ($model->getAttribute('store_id') === null) {
                $model->setAttribute('store_id', app(StoreContext::class)->id());
            }
        });
    }

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}

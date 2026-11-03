<?php

namespace App\Models\Kassa\Scopes;

use App\Support\Kassa\StoreContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Изоляция магазинов: любой запрос к модели видит только строки текущего магазина.
 * Колонка указывается с именем таблицы, поэтому фильтр работает и в запросах с join.
 */
class StoreScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('store_id'), app(StoreContext::class)->id());
    }
}

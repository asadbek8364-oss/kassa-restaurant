<?php

namespace App\Models\Restaurant;

use App\Models\Kassa\Concerns\BelongsToStore;
use App\Models\Restaurant\Concerns\SyncsFromOnline;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Категория меню. */
class Category extends Model
{
    use BelongsToStore;
    use SoftDeletes;
    use SyncsFromOnline;

    protected $table = 'restaurant_categories';

    protected $fillable = ['name', 'sort'];

    protected $casts = [
        'version' => 'integer',
        'synced_at' => 'datetime',
        'sort' => 'integer',
    ];

    public function syncFields(): array
    {
        return ['name', 'sort', 'deleted_at'];
    }

    public function dishes()
    {
        return $this->hasMany(Dish::class, 'category_id');
    }
}

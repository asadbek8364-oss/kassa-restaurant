<?php

namespace App\Models\Kassa;

use App\Support\Kassa\StoreContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Сотрудник. Продавец работает в одном магазине (store_id), владелец — во всех (store_id = NULL).
 * В магазине видны его продавцы и все владельцы.
 */
class Employee extends Model
{
    public const ROLES = ['owner', 'seller'];

    protected $table = 'kassa_employees';

    protected $fillable = ['name', 'role', 'phone', 'pin', 'active', 'store_id'];

    protected $hidden = ['pin'];

    protected $casts = [
        'active' => 'boolean',
        'pin' => 'hashed',
        'store_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('kassa_store', function (Builder $q) {
            $q->where(fn ($w) => $w->where('kassa_employees.store_id', app(StoreContext::class)->id())
                ->orWhereNull('kassa_employees.store_id'));
        });
        // Владелец — общий для всех магазинов; продавец без магазина попадает в текущий.
        static::saving(function (self $e) {
            if ($e->isOwner()) {
                $e->store_id = null;
            } elseif ($e->store_id === null) {
                $e->store_id = app(StoreContext::class)->id();
            }
        });
    }

    public function isOwner(): bool
    {
        return $this->role === 'owner';
    }

    /** Может ли сотрудник работать в этом магазине. */
    public function worksIn(Store $store): bool
    {
        return $this->isOwner() || $this->store_id === $store->id;
    }

    public function tokens()
    {
        return $this->hasMany(Token::class, 'employee_id');
    }
}

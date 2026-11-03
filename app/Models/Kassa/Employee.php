<?php

namespace App\Models\Kassa;

use App\Models\Restaurant\Concerns\SyncsFromOnline;
use App\Support\Kassa\StoreContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Сотрудник. Продавец, администратор, официант и повар работают в одном магазине (ресторане, store_id),
 * владелец — во всех (store_id = NULL). В магазине видны его сотрудники и все владельцы.
 * Сотрудники ресторана приходят с онлайн-сервера (SyncsFromOnline); владельцы между серверами не передаются.
 */
class Employee extends Model
{
    use SyncsFromOnline;

    /** owner — владелец, seller — продавец кассы, admin — администратор ресторана, waiter — официант, cook — повар. */
    public const ROLES = ['owner', 'seller', 'admin', 'waiter', 'cook'];

    protected $table = 'kassa_employees';

    protected $fillable = ['name', 'role', 'phone', 'pin', 'active', 'store_id'];

    protected $hidden = ['pin'];

    protected $casts = [
        'active' => 'boolean',
        'pin' => 'hashed',
        'store_id' => 'integer',
        'version' => 'integer',
        'synced_at' => 'datetime',
    ];

    public function syncFields(): array
    {
        return ['name', 'role', 'phone', 'pin', 'active'];
    }

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

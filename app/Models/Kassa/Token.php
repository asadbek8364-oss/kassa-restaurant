<?php

namespace App\Models\Kassa;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Вход сотрудника на устройстве. store_id — магазин, в котором он сейчас работает. */
class Token extends Model
{
    public const TTL_DAYS = 30;

    protected $table = 'kassa_tokens';

    protected $fillable = ['employee_id', 'store_id', 'token_hash', 'last_used_at', 'expires_at'];

    protected $casts = [
        'store_id' => 'integer',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** Сотрудник любого магазина: токен ищется до того, как известен магазин запроса. */
    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id')->withoutGlobalScopes();
    }

    /** Создаёт токен и возвращает его открытое значение (в базе хранится только хеш). */
    public static function issue(Employee $employee, Store $store): string
    {
        $plain = Str::random(48);
        static::create([
            'employee_id' => $employee->id,
            'store_id' => $store->id,
            'token_hash' => hash('sha256', $plain),
            'last_used_at' => now(),
            'expires_at' => now()->addDays(config('kassa.token_ttl_days', self::TTL_DAYS)),
        ]);

        return $plain;
    }

    public static function findValid(string $plain): ?self
    {
        return static::query()
            ->where('token_hash', hash('sha256', $plain))
            ->where('expires_at', '>', now())
            ->with('employee')
            ->first();
    }
}

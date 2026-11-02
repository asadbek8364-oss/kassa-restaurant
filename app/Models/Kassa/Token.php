<?php

namespace App\Models\Kassa;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Token extends Model
{
    public const TTL_DAYS = 30;

    protected $table = 'kassa_tokens';

    protected $fillable = ['employee_id', 'token_hash', 'last_used_at', 'expires_at'];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /** Создаёт токен и возвращает его открытое значение (в базе хранится только хеш). */
    public static function issue(Employee $employee): string
    {
        $plain = Str::random(48);
        static::create([
            'employee_id' => $employee->id,
            'token_hash' => hash('sha256', $plain),
            'last_used_at' => now(),
            'expires_at' => now()->addDays(self::TTL_DAYS),
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

<?php

namespace App\Support\Kassa;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Время магазина. В базе даты лежат в часовом поясе приложения (app.timezone),
 * а «сегодня», дни и часы отчётов считаются в часовом поясе магазина (kassa.timezone).
 */
class Clock
{
    public static function tz(): string
    {
        return config('kassa.timezone', 'Asia/Tashkent');
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::tz());
    }

    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }

    /** Граница периода в формате для запроса к базе. */
    public static function toDb(DateTimeInterface $t): string
    {
        return CarbonImmutable::instance($t)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }

    /** Значение из базы (строка или Carbon) → время магазина. */
    public static function local(DateTimeInterface|string $value): CarbonImmutable
    {
        $t = is_string($value)
            ? CarbonImmutable::parse($value, config('app.timezone'))
            : CarbonImmutable::instance($value);

        return $t->setTimezone(self::tz());
    }

    public static function iso(DateTimeInterface|string|null $value): ?string
    {
        return $value === null ? null : self::local($value)->toIso8601String();
    }

    public static function day(DateTimeInterface|string $value): string
    {
        return self::local($value)->format('Y-m-d');
    }
}

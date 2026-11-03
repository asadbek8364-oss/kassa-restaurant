<?php

namespace App\Support\Restaurant;

use Closure;

/**
 * Пока применяются данные, пришедшие при синхронизации, модели справочников не помечают себя
 * изменёнными (synced_at) и не увеличивают версию — это не правка пользователя.
 */
class SyncGate
{
    private static int $depth = 0;

    public static function applying(): bool
    {
        return self::$depth > 0;
    }

    public static function apply(Closure $fn): mixed
    {
        self::$depth++;
        try {
            return $fn();
        } finally {
            self::$depth--;
        }
    }
}

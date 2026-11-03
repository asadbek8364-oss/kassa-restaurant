<?php

namespace App\Support\Restaurant;

/**
 * Режим сервера (DEPLOY_MODE): local — сервер в ресторане, online — облачная копия.
 */
class Mode
{
    public const LOCAL = 'local';

    public const ONLINE = 'online';

    public static function current(): string
    {
        return config('restaurant.deploy_mode') === self::ONLINE ? self::ONLINE : self::LOCAL;
    }

    public static function online(): bool
    {
        return self::current() === self::ONLINE;
    }

    public static function local(): bool
    {
        return ! self::online();
    }

    public static function label(): string
    {
        return self::online() ? 'Онлайн-сервер' : 'Локальный сервер ресторана';
    }
}

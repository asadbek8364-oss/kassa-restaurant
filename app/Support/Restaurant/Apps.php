<?php

namespace App\Support\Restaurant;

/**
 * Какие роли открывают какое приложение. Роль сотрудника определяет его «домашний» интерфейс:
 * владелец и администратор — /admin, официант — /waiter, повар — /kitchen, продавец — касса /kassa/.
 * Администратор и владелец могут открыть и приложения официанта и кухни (подменить сотрудника),
 * но не наоборот: официант и повар в админку не попадают.
 */
class Apps
{
    public const ACCESS = [
        'admin' => ['owner', 'admin'],
        'waiter' => ['owner', 'admin', 'waiter'],
        'kitchen' => ['owner', 'admin', 'cook'],
        'kassa' => ['owner', 'admin', 'seller'],
    ];

    /** Названия приложений для сообщений. */
    public const NAMES = [
        'admin' => 'админка ресторана',
        'waiter' => 'приложение официанта',
        'kitchen' => 'экран кухни',
        'kassa' => 'касса и склад',
    ];

    public const ROLE_NAMES = [
        'owner' => 'Владелец',
        'admin' => 'Администратор',
        'waiter' => 'Официант',
        'cook' => 'Повар',
        'seller' => 'Продавец',
    ];

    /** Роли сотрудников ресторана, которыми управляет админка (владелец — общий для всех ресторанов). */
    public const STAFF_ROLES = ['admin', 'waiter', 'cook'];

    public static function allows(string $app, ?string $role): bool
    {
        return in_array($role, self::ACCESS[$app] ?? [], true);
    }

    /** @return list<string> */
    public static function roles(string $app): array
    {
        return self::ACCESS[$app] ?? [];
    }

    public static function home(string $role): string
    {
        return match ($role) {
            'owner', 'admin' => 'admin',
            'waiter' => 'waiter',
            'cook' => 'kitchen',
            default => 'kassa',
        };
    }

    public static function url(string $app): string
    {
        return $app === 'kassa' ? '/kassa/' : '/'.$app;
    }
}

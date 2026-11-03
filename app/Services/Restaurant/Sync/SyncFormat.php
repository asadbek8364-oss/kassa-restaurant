<?php

namespace App\Services\Restaurant\Sync;

use App\Models\Kassa\Employee;
use App\Models\Kassa\Store;
use App\Models\Restaurant\Category;
use App\Models\Restaurant\Dish;
use App\Models\Restaurant\Settings;
use App\Models\Restaurant\Table;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Формат обмена между локальным и онлайн-сервером. Записи идентифицируются uuid (id на серверах разные),
 * ссылки на другие записи — тоже через uuid (category_uuid, waiter_uuid…), время — ISO 8601 со смещением.
 * Справочники (меню, столики, сотрудники, настройки) описываются одинаково в обе стороны.
 */
class SyncFormat
{
    /** Версия формата: онлайн-сервер отклоняет пакеты неизвестного формата. */
    public const VERSION = 1;

    /** Роли сотрудников, которые передаются между серверами (владельцы — свои на каждом сервере). */
    public const STAFF_ROLES = ['admin', 'waiter', 'cook', 'seller'];

    public static function time(DateTimeInterface|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ($value instanceof DateTimeInterface ? Carbon::instance($value) : Carbon::parse($value, config('app.timezone')))->toIso8601String();
    }

    public static function parseTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    public static function isUuid(mixed $value): bool
    {
        return is_string($value) && Str::isUuid($value);
    }

    // ---------------------------------------------------------------- справочники → массив

    public static function categoryRow(Category $c): array
    {
        return ['uuid' => $c->uuid, 'version' => (int) $c->version, 'deleted' => $c->trashed(), 'name' => $c->name, 'sort' => (int) $c->sort];
    }

    /** @param array<int, string> $categoryUuids id категории → uuid */
    public static function dishRow(Dish $d, array $categoryUuids): array
    {
        return [
            'uuid' => $d->uuid, 'version' => (int) $d->version, 'deleted' => $d->trashed(),
            'category_uuid' => $categoryUuids[$d->category_id] ?? null,
            'name' => $d->name, 'description' => $d->description, 'price' => (int) $d->price,
            'active' => (bool) $d->active, 'stop' => (bool) $d->stop, 'sort' => (int) $d->sort,
        ];
    }

    public static function tableRow(Table $t): array
    {
        return [
            'uuid' => $t->uuid, 'version' => (int) $t->version, 'deleted' => $t->trashed(),
            'name' => $t->name, 'seats' => (int) $t->seats, 'active' => (bool) $t->active, 'sort' => (int) $t->sort,
        ];
    }

    /** PIN передаётся только хешем — открытого PIN нет ни на одном сервере. */
    public static function staffRow(Employee $e): array
    {
        return [
            'uuid' => $e->uuid, 'version' => (int) $e->version,
            'name' => $e->name, 'role' => $e->role, 'phone' => $e->phone, 'active' => (bool) $e->active,
            'pin' => $e->getRawOriginal('pin') ?? $e->getAttributes()['pin'] ?? null,
        ];
    }

    public static function settingsRow(Settings $s, Store $store): array
    {
        return [
            'uuid' => $s->uuid, 'version' => (int) $s->version,
            'store_name' => $store->name, 'store_address' => $store->address,
            'cook_minutes' => (int) $s->cook_minutes, 'receipt_header' => $s->receipt_header, 'receipt_footer' => $s->receipt_footer,
        ];
    }

    // ---------------------------------------------------------------- массив → справочник

    public static function fillCategory(Category $m, array $r): void
    {
        $m->name = self::str($r['name'] ?? '', 40) ?? 'Без названия';
        $m->sort = self::int($r['sort'] ?? 0);
    }

    public static function fillDish(Dish $m, array $r, ?int $categoryId): void
    {
        $m->category_id = $categoryId;
        $m->name = self::str($r['name'] ?? '', 80) ?? 'Без названия';
        $m->description = self::str($r['description'] ?? null, 300);
        $m->price = self::int($r['price'] ?? 0);
        $m->active = (bool) ($r['active'] ?? true);
        $m->stop = (bool) ($r['stop'] ?? false);
        $m->sort = self::int($r['sort'] ?? 0);
    }

    public static function fillTable(Table $m, array $r): void
    {
        $m->name = self::str($r['name'] ?? '', 20) ?? '?';
        $m->seats = max(1, min(100, self::int($r['seats'] ?? 4)));
        $m->active = (bool) ($r['active'] ?? true);
        $m->sort = self::int($r['sort'] ?? 0);
    }

    /** Хеш PIN записывается как есть, мимо приведения 'hashed' (оно бы проверяло параметры bcrypt этого сервера). */
    public static function fillStaff(Employee $m, array $r): void
    {
        $m->name = self::str($r['name'] ?? '', 60) ?? 'Сотрудник';
        $m->role = in_array($r['role'] ?? null, self::STAFF_ROLES, true) ? $r['role'] : 'waiter';
        $m->phone = self::str($r['phone'] ?? null, 20);
        $m->active = (bool) ($r['active'] ?? true);
        if (is_string($r['pin'] ?? null) && $r['pin'] !== '') {
            $m->setRawAttributes(array_merge($m->getAttributes(), ['pin' => $r['pin']]));
        }
    }

    public static function fillSettings(Settings $s, Store $store, array $r): void
    {
        $s->cook_minutes = max(1, min(240, self::int($r['cook_minutes'] ?? 15)));
        $s->receipt_header = self::str($r['receipt_header'] ?? null, 120);
        $s->receipt_footer = self::str($r['receipt_footer'] ?? null, 120);
        $name = self::str($r['store_name'] ?? null, 60);
        if ($name !== null) {
            $store->name = $name;
        }
        $store->address = self::str($r['store_address'] ?? null, 120);
    }

    /** Удалена ли запись (мягкое удаление). */
    public static function markDeleted(Model $m, bool $deleted): void
    {
        $m->setAttribute('deleted_at', $deleted ? ($m->getAttribute('deleted_at') ?? now()) : null);
    }

    public static function str(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    public static function int(mixed $value): int
    {
        return max(0, (int) $value);
    }
}

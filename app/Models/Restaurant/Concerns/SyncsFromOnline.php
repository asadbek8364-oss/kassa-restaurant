<?php

namespace App\Models\Restaurant\Concerns;

use App\Support\Restaurant\Mode;
use App\Support\Restaurant\SyncGate;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Справочник, главная копия которого — на онлайн-сервере (меню, столики, сотрудники, настройки).
 *
 * - uuid — один и тот же на обоих серверах;
 * - version — версия записи на онлайн-сервере: растёт при каждом изменении там;
 * - synced_at — на локальном сервере: когда запись последний раз сверена с онлайн-сервером;
 *   NULL — есть локальные изменения, которые ещё не отправлены.
 *
 * Правка пользователя на онлайн-сервере увеличивает version, на локальном — сбрасывает synced_at.
 * Данные, применяемые при синхронизации (SyncGate), ничего не помечают.
 */
trait SyncsFromOnline
{
    /** Поля, изменение которых передаётся между серверами. */
    abstract public function syncFields(): array;

    public static function bootSyncsFromOnline(): void
    {
        static::creating(function (self $m) {
            if (! $m->getAttribute('uuid')) {
                $m->setAttribute('uuid', (string) Str::uuid());
            }
            if ($m->getAttribute('version') === null) {
                $m->setAttribute('version', 1);
            }
        });

        static::saving(function (self $m) {
            if (SyncGate::applying() || ($m->exists && ! $m->isDirty($m->syncFields()))) {
                return;
            }
            $m->markChangedForSync();
        });

        // Мягкое удаление пишет в базу запросом, без saving — отмечаем отдельно.
        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::deleted(function (self $m) {
                if (SyncGate::applying() || $m->isForceDeleting()) {
                    return;
                }
                $m->markChangedForSync();
                $m->newQueryWithoutScopes()->whereKey($m->getKey())
                    ->update(['version' => $m->version, 'synced_at' => $m->synced_at]);
            });
        }
    }

    /** Запись изменена пользователем: на онлайн-сервере — новая версия, на локальном — ждёт отправки. */
    public function markChangedForSync(): void
    {
        if (Mode::online()) {
            if ($this->exists) {
                $this->version = (int) $this->version + 1;
            }
        } else {
            $this->synced_at = null;
        }
    }

    /** На локальном сервере: изменения записи ещё не отправлены на онлайн-сервер. */
    public function hasUnsyncedChanges(): bool
    {
        return $this->synced_at === null;
    }
}

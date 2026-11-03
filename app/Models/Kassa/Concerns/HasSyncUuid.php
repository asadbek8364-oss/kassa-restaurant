<?php

namespace App\Models\Kassa\Concerns;

use Illuminate\Support\Str;

/**
 * Общий идентификатор записи для синхронизации с онлайн-сервером (id на серверах разные).
 */
trait HasSyncUuid
{
    public static function bootHasSyncUuid(): void
    {
        static::creating(function ($model) {
            if (! $model->getAttribute('uuid')) {
                $model->setAttribute('uuid', (string) Str::uuid());
            }
        });
    }
}

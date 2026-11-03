<?php

namespace App\Services\Restaurant\Sync;

use App\Models\Restaurant\Settings;

/** Создаёт канал к онлайн-серверу по настройкам ресторана. Тесты подменяют фабрику в контейнере. */
class SyncTransportFactory
{
    public function make(Settings $settings): SyncTransport
    {
        if (! $settings->syncConfigured()) {
            throw new SyncException('Синхронизация не настроена: укажите адрес онлайн-сервера и sync-токен');
        }

        return new HttpSyncTransport((string) $settings->sync_url, (string) $settings->sync_token, (int) config('restaurant.sync.timeout', 30));
    }
}

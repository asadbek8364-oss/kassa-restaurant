<?php

namespace App\Services\Restaurant\Sync;

/**
 * Канал локального сервера к онлайн-серверу. Настоящий — HttpSyncTransport (HTTPS REST + sync-токен),
 * в тестах — прямой вызов SyncServer на второй базе.
 */
interface SyncTransport
{
    /** @throws SyncException */
    public function ping(): array;

    /** @throws SyncException */
    public function push(array $payload): array;

    /** @throws SyncException */
    public function pull(): array;
}

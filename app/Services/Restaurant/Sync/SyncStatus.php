<?php

namespace App\Services\Restaurant\Sync;

use App\Models\Restaurant\Settings;
use App\Models\Restaurant\SyncLog;
use App\Support\Kassa\Clock;
use App\Support\Restaurant\Mode;
use App\Support\Restaurant\Presenter;

/**
 * Экран статуса сервера и синхронизации в админке, пометка «есть неотправленные данные» на главном экране.
 */
class SyncStatus
{
    /** Через сколько часов без успешной синхронизации это считается проблемой. */
    public const STALE_HOURS = 26;

    public function __construct(private readonly SyncClient $client) {}

    public function summary(): array
    {
        $settings = Settings::current();
        $base = [
            'mode' => Mode::current(),
            'mode_name' => Mode::label(),
            'schedule' => config('restaurant.sync.time'),
            'timezone' => config('kassa.timezone'),
            'server_time' => Clock::now()->toIso8601String(),
        ];

        if (Mode::online()) {
            $lastPush = SyncLog::where('direction', 'push')->latest('id')->first();
            $lastPull = SyncLog::where('direction', 'pull')->latest('id')->first();

            return $base + [
                'token_issued' => $settings->sync_token_hash !== null,
                'token_hint' => $settings->sync_token_hint,
                'token_issued_at' => Clock::iso($settings->sync_token_issued_at),
                'last_push' => $lastPush ? Presenter::syncLog($lastPush) : null,
                'last_pull' => $lastPull ? Presenter::syncLog($lastPull) : null,
                'attention' => false,
            ];
        }

        $configured = $settings->syncConfigured();
        $pending = $this->client->pending();
        $docs = $pending['orders'] + $pending['sales'] + $pending['stock'];
        $last = SyncLog::where('direction', 'sync')->latest('id')->first();
        $lastOk = SyncLog::where('direction', 'sync')->where('status', 'ok')->latest('id')->first();
        $stale = ! $lastOk || $lastOk->finished_at === null || $lastOk->finished_at->lt(now()->subHours(self::STALE_HOURS));

        return $base + [
            'configured' => $configured,
            'url' => $settings->sync_url,
            'token_set' => (string) $settings->sync_token !== '',
            'pending' => $pending,
            'pending_total' => $docs + $pending['menu'],
            'last' => $last ? Presenter::syncLog($last) : null,
            'last_ok_at' => Clock::iso($lastOk?->finished_at),
            // «есть неотправленные данные»: последняя попытка не удалась или давно не было успешной
            'attention' => $configured && ($docs + $pending['menu']) > 0 && (($last && $last->status === 'failed') || $stale),
        ];
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Kassa\Store;
use App\Models\Restaurant\Settings;
use App\Models\Restaurant\SyncLog;
use App\Services\Restaurant\Sync\SyncClient;
use App\Support\Restaurant\Mode;
use Illuminate\Console\Command;

/**
 * Ежедневная синхронизация локального сервера с онлайн-сервером (Laravel Scheduler, см. routes/console.php).
 * --retry — запуск каждый час: синхронизирует только рестораны, у которых последняя попытка не удалась.
 */
class RestaurantSyncCommand extends Command
{
    protected $signature = 'restaurant:sync {--retry : Только рестораны с неудачной последней синхронизацией} {--store= : id ресторана}';

    protected $description = 'Ресторан: отправить заказы и продажи на онлайн-сервер и получить меню, столики, сотрудников и настройки';

    public function handle(SyncClient $client): int
    {
        if (Mode::online()) {
            $this->info('Это онлайн-сервер: синхронизацию запускают локальные серверы ресторанов.');

            return self::SUCCESS;
        }

        $storeIds = Settings::withoutGlobalScopes()->whereNotNull('sync_url')->whereNotNull('sync_token')
            ->when($this->option('store'), fn ($q, $id) => $q->where('store_id', (int) $id))
            ->pluck('store_id');
        $stores = Store::whereIn('id', $storeIds)->orderBy('id')->get();
        if ($stores->isEmpty()) {
            $this->line('Синхронизация не настроена ни для одного ресторана (админка → Настройки → Синхронизация).');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($stores as $store) {
            if ($this->option('retry')) {
                $last = SyncLog::withoutGlobalScopes()->where('store_id', $store->id)->where('direction', 'sync')->latest('id')->first();
                if (! $last || $last->status !== 'failed') {
                    continue;
                }
            }
            $log = $client->run($store, $this->option('retry') ? 'retry' : 'schedule');
            if ($log->status === 'ok') {
                $this->info("«{$store->name}»: отправлено ".json_encode($log->sent, JSON_UNESCAPED_UNICODE).', получено '.json_encode($log->received, JSON_UNESCAPED_UNICODE));
            } else {
                $failed++;
                $this->error("«{$store->name}»: {$log->error}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

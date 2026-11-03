<?php

namespace App\Console\Commands;

use App\Models\Kassa\Store;
use App\Models\Restaurant\Settings;
use App\Services\Restaurant\Printing\PrintQueue;
use App\Support\Kassa\StoreContext;
use Illuminate\Console\Command;

/**
 * Повтор печати на сетевой принтер по расписанию (каждую минуту) — на случай, если экран кухни выключен
 * и некому запускать повторы при опросе.
 */
class RestaurantPrintQueueCommand extends Command
{
    protected $signature = 'restaurant:print-queue';

    protected $description = 'Ресторан: отправить на сетевой принтер кухни чеки, которые ждут печати';

    public function handle(PrintQueue $queue, StoreContext $context): int
    {
        $storeIds = Settings::withoutGlobalScopes()->where('printer_mode', 'network')->pluck('store_id');
        foreach (Store::whereIn('id', $storeIds)->get() as $store) {
            $result = $context->run($store, fn () => $queue->processDue());
            if ($result['printed'] || $result['failed']) {
                $this->line("«{$store->name}»: напечатано {$result['printed']}".($result['error'] ? ", ошибка: {$result['error']}" : ''));
            }
        }

        return self::SUCCESS;
    }
}

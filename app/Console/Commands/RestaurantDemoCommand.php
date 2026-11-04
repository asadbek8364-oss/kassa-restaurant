<?php

namespace App\Console\Commands;

use App\Services\Restaurant\RestaurantDemoService;
use Illuminate\Console\Command;

class RestaurantDemoCommand extends Command
{
    protected $signature = 'restaurant:demo {--force : Не спрашивать подтверждение}';

    protected $description = 'Ресторан: создать демо-ресторан — 22 блюда, 8 столиков, администратор, официант, повар и кассир, заказы за столиками и навынос (только при KASSA_DEMO_ENABLED=true вне production)';

    /** На рабочем сервере и без KASSA_DEMO_ENABLED=true команды нет в artisan. */
    public function isEnabled(): bool
    {
        return RestaurantDemoService::allowed();
    }

    public function handle(RestaurantDemoService $demo): int
    {
        if (! RestaurantDemoService::allowed()) {
            $this->error('Демо-данные отключены: нужен KASSA_DEMO_ENABLED=true и APP_ENV не production.');

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm('Будет создан (или пересоздан) «'.RestaurantDemoService::NAME.'». Другие рестораны и магазины не изменятся. Продолжить?')) {
            return self::FAILURE;
        }

        $store = $demo->load();
        $this->info("Готово: «{$store->name}». ".RestaurantDemoService::HINT.'.');
        $this->line('Админка: /admin · официант: /waiter · кассир: /cashier · кухня: /kitchen');

        return self::SUCCESS;
    }
}

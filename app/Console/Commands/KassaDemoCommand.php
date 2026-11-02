<?php

namespace App\Console\Commands;

use App\Services\Kassa\KassaDemoService;
use Illuminate\Console\Command;

class KassaDemoCommand extends Command
{
    protected $signature = 'kassa:demo {--force : Не спрашивать подтверждение}';

    protected $description = '«Касса и склад»: заменить все данные кассы демо-магазином (только при KASSA_DEMO_ENABLED=true вне production)';

    /** На рабочем сервере и без KASSA_DEMO_ENABLED=true команды просто нет в artisan. */
    public function isEnabled(): bool
    {
        return KassaDemoService::allowed();
    }

    public function handle(KassaDemoService $demo): int
    {
        if (! KassaDemoService::allowed()) {
            $this->error('Демо-данные отключены: нужен KASSA_DEMO_ENABLED=true и APP_ENV не production.');

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm('Все товары, чеки, документы и сотрудники кассы будут заменены демо-данными. Продолжить?')) {
            return self::FAILURE;
        }

        $demo->load();
        $this->info('Демо-магазин загружен. PIN владельца: 1111, продавцов: 2222, 3333, 4444.');

        return self::SUCCESS;
    }
}

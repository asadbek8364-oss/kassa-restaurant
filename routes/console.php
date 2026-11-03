<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('crm:sync-segments')->dailyAt('01:00');
Schedule::command('crm:generate-reminders')->dailyAt('01:15');
Schedule::command('reorder:generate')->dailyAt('02:00');
Schedule::command('transactions:expire')->hourly();

// Ресторан: синхронизация локального сервера с онлайн-сервером раз в день (время ресторана); при неудаче — повтор каждый час.
Schedule::command('restaurant:sync')->dailyAt(config('restaurant.sync.time', '03:40'))->timezone(config('kassa.timezone', 'Asia/Tashkent'))->withoutOverlapping(120);
Schedule::command('restaurant:sync --retry')->hourlyAt(17)->withoutOverlapping(120);
// Ресторан: чеки, которые ждут сетевой принтер кухни (повтор при недоступности принтера).
Schedule::command('restaurant:print-queue')->everyMinute()->withoutOverlapping(5);

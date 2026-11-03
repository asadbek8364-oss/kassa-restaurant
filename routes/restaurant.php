<?php

use App\Http\Controllers\Restaurant\PageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Ресторан: три приложения — /admin (администратор), /waiter (официант), /kitchen (кухня)
|--------------------------------------------------------------------------
|
| Каждое — отдельная PWA: своя страница и layout, свой манифест и service worker.
| Данные — через /api/restaurant (routes/api.php).
|
*/
$apps = array_keys(PageController::APPS);

Route::get('/{app}', [PageController::class, 'show'])->whereIn('app', $apps)->name('restaurant.page');
Route::get('/{app}/manifest.webmanifest', [PageController::class, 'manifest'])->whereIn('app', $apps)->name('restaurant.manifest');
Route::get('/{app}/sw.js', [PageController::class, 'serviceWorker'])->whereIn('app', $apps)->name('restaurant.sw');

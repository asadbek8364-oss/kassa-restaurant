<?php

namespace App\Http\Controllers\Restaurant;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Страницы четырёх приложений ресторана. У каждого свой адрес, свой layout и своя конфигурация PWA
 * (манифест с отдельным id и service worker со своей областью) — на устройство они ставятся
 * как отдельные приложения. Всё содержимое — в public/restaurant, внешних ресурсов нет,
 * поэтому приложения работают в локальной сети ресторана без интернета.
 * Официант и кассир работают с залом одинаково: общий модуль floor.js и свой файл приложения.
 */
class PageController extends Controller
{
    public const APPS = [
        'admin' => [
            'name' => 'Ресторан · Админка',
            'short' => 'Админка',
            'description' => 'Меню, столики, сотрудники, отчёты, принтер и синхронизация ресторана',
            'theme' => '#3730a3',
            'background' => '#f4f5fb',
            'display' => 'standalone',
            'orientation' => 'any',
        ],
        'waiter' => [
            'name' => 'Ресторан · Официант',
            'short' => 'Официант',
            'description' => 'Схема зала, заказы на столики, отправка на кухню, счёт и оплата',
            'theme' => '#0f766e',
            'background' => '#f2f6f5',
            'display' => 'standalone',
            'orientation' => 'any',
        ],
        'cashier' => [
            'name' => 'Ресторан · Кассир',
            'short' => 'Кассир',
            'description' => 'Зал и открытые заказы, заказы навынос, приём оплаты наличными и картой через терминал',
            'theme' => '#0369a1',
            'background' => '#f2f6f9',
            'display' => 'standalone',
            'orientation' => 'any',
        ],
        'kitchen' => [
            'name' => 'Ресторан · Кухня',
            'short' => 'Кухня',
            'description' => 'Экран кухни: заказы колонками «Новые → Готовятся → Готово», только просмотр',
            'theme' => '#ffffff',
            'background' => '#ffffff',
            'display' => 'fullscreen',
            'orientation' => 'landscape',
        ],
    ];

    /** Приложения зала: официант и кассир — общий модуль floor.js. */
    public const FLOOR = ['waiter', 'cashier'];

    public function show(string $app): Response
    {
        return response()
            ->view("restaurant.{$app}", ['app' => $app, 'meta' => self::APPS[$app], 'v' => $this->version($app), 'assets' => '/restaurant', 'demo' => false, 'floor' => in_array($app, self::FLOOR, true)])
            ->header('Cache-Control', 'no-cache');
    }

    public function manifest(string $app): JsonResponse
    {
        $meta = self::APPS[$app];

        return response()->json([
            'id' => "/{$app}",
            'name' => $meta['name'],
            'short_name' => $meta['short'],
            'description' => $meta['description'],
            'lang' => 'ru',
            'dir' => 'ltr',
            'start_url' => "/{$app}",
            'scope' => "/{$app}",
            'display' => $meta['display'],
            'orientation' => $meta['orientation'],
            'background_color' => $meta['background'],
            'theme_color' => $meta['theme'],
            'categories' => ['business', 'food'],
            'icons' => [
                ['src' => "/restaurant/icons/{$app}.svg", 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any'],
                ['src' => "/restaurant/icons/{$app}-192.png", 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => "/restaurant/icons/{$app}-512.png", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => "/restaurant/icons/{$app}-512.png", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'no-cache'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Service worker приложения. Область — /admin, /waiter, /cashier или /kitchen (заголовок Service-Worker-Allowed),
     * версия кэша — по времени изменения файлов: обновлённое приложение подхватывается само.
     */
    public function serviceWorker(string $app): Response
    {
        $v = $this->version($app);
        $shell = [
            "/{$app}",
            "/{$app}/manifest.webmanifest",
            "/restaurant/restaurant.css?v={$v}",
            "/restaurant/common.js?v={$v}",
            ...(in_array($app, self::FLOOR, true) ? ["/restaurant/floor.js?v={$v}"] : []),
            "/restaurant/{$app}.js?v={$v}",
            "/restaurant/icons/{$app}.svg",
            "/restaurant/icons/{$app}-192.png",
        ];
        $js = view('restaurant.sw', ['app' => $app, 'cache' => "restoran-{$app}-{$v}", 'shell' => $shell])->render();

        return response($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => "/{$app}",
        ]);
    }

    /** Версия ресурсов приложения — время последнего изменения его файлов. */
    private function version(string $app): string
    {
        $dir = public_path('restaurant');
        $files = ['restaurant.css', 'common.js', ...(in_array($app, self::FLOOR, true) ? ['floor.js'] : []), "{$app}.js"];
        $times = array_map(fn ($f) => @filemtime($dir.'/'.$f) ?: 0, $files);

        return base_convert((string) max($times), 10, 36);
    }
}

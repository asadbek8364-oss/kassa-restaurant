<?php

namespace App\Console\Commands;

use App\Http\Controllers\Restaurant\PageController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Статическая демо-версия ресторана (каталог demo/): лаунчер, приложения и руководство работают в браузере
 * без сервера — вместо него эмулятор API (demo/assets/demo-server.js). Команда собирает страницы приложений
 * из тех же Blade-layout'ов, что и на сервере, и копирует их скрипты и стили, чтобы демо не расходилось с программой.
 * Лаунчер, руководство и эмулятор лежат в demo/ и правятся там.
 */
class RestaurantDemoSiteCommand extends Command
{
    protected $signature = 'restaurant:demo-site {--out=demo : Каталог демо-сайта относительно проекта}';

    protected $description = 'Ресторан: собрать страницы приложений для статической демо-версии (demo/)';

    /** Файлы приложений из public/restaurant, которые нужны демо-версии. */
    public const ASSETS = ['restaurant.css', 'common.js', 'floor.js', 'admin.js', 'waiter.js', 'cashier.js', 'kitchen.js'];

    public function handle(): int
    {
        $out = base_path(trim((string) $this->option('out'), '/'));
        File::ensureDirectoryExists($out.'/assets/icons');

        foreach (self::ASSETS as $file) {
            File::copy(public_path('restaurant/'.$file), $out.'/assets/'.$file);
        }
        foreach (File::files(public_path('restaurant/icons')) as $icon) {
            File::copy($icon->getPathname(), $out.'/assets/icons/'.$icon->getFilename());
        }

        // версия ресурсов — по содержимому, чтобы сборка на любом компьютере давала одинаковые страницы
        $hashes = array_map(fn ($f) => is_file($out.'/assets/'.$f) ? md5_file($out.'/assets/'.$f) : '', [...self::ASSETS, 'demo-server.js']);
        $version = substr(md5(implode('', $hashes)), 0, 10);

        foreach (PageController::APPS as $app => $meta) {
            $html = view("restaurant.{$app}", [
                'app' => $app, 'meta' => $meta, 'v' => $version, 'assets' => 'assets', 'demo' => true,
                'floor' => in_array($app, PageController::FLOOR, true),
            ])->render();
            File::put($out.'/'.$app.'.html', $html);
        }

        $this->info('Демо-версия собрана в '.$out.': '.implode(', ', array_map(fn ($a) => $a.'.html', array_keys(PageController::APPS))).' и assets/.');
        $this->line('Откройте '.$out.'/index.html через любой веб-сервер, например: php -S 127.0.0.1:8080 -t '.$this->option('out'));

        return self::SUCCESS;
    }
}

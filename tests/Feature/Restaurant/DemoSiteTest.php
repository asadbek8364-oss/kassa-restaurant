<?php

namespace Tests\Feature\Restaurant;

use App\Console\Commands\RestaurantDemoSiteCommand;
use App\Http\Controllers\Restaurant\PageController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Статическая демо-версия (demo/): страницы приложений собираются из тех же layout'ов и скриптов, что и на сервере,
 * но вместо сервера работают с эмулятором API в браузере.
 */
class DemoSiteTest extends TestCase
{
    use RefreshDatabase;

    private const OUT = 'storage/framework/testing/restaurant-demo-site';

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path(self::OUT));
        parent::tearDown();
    }

    public function test_demo_site_is_built_from_the_apps_and_matches_the_committed_demo(): void
    {
        $dir = base_path(self::OUT);
        File::ensureDirectoryExists($dir.'/assets');
        File::copy(base_path('demo/assets/demo-server.js'), $dir.'/assets/demo-server.js');

        $this->artisan('restaurant:demo-site', ['--out' => self::OUT])->assertSuccessful();

        foreach (RestaurantDemoSiteCommand::ASSETS as $file) {
            $this->assertFileEquals(public_path('restaurant/'.$file), $dir.'/assets/'.$file);
        }
        $this->assertFileExists($dir.'/assets/icons/kitchen-192.png');

        foreach (array_keys(PageController::APPS) as $app) {
            $html = File::get($dir.'/'.$app.'.html');
            $this->assertStringContainsString('<body class="app-'.$app.'"', $html);
            // эмулятор подключается раньше приложения; ссылки относительные, без манифеста PWA
            $this->assertMatchesRegularExpression('~<script src="assets/demo-server\.js\?v=\w+"></script>\s*<script src="assets/common\.js\?v=\w+"></script>~', $html);
            $this->assertStringNotContainsString('manifest.webmanifest', $html);
            $this->assertStringNotContainsString('"/restaurant/', $html);

            // demo/ лежит в репозитории готовым к публикации и не должен отставать от приложений
            $this->assertFileEquals($dir.'/'.$app.'.html', base_path('demo/'.$app.'.html'), "demo/{$app}.html устарел: выполните php artisan restaurant:demo-site");
        }
        foreach (RestaurantDemoSiteCommand::ASSETS as $file) {
            $this->assertFileEquals(public_path('restaurant/'.$file), base_path('demo/assets/'.$file), "demo/assets/{$file} устарел: выполните php artisan restaurant:demo-site");
        }
    }

    public function test_real_pages_do_not_load_the_emulator(): void
    {
        foreach (array_keys(PageController::APPS) as $app) {
            $this->get('/'.$app)->assertOk()
                ->assertDontSee('demo-server.js', false)
                ->assertSee('<script src="/restaurant/common.js', false)
                ->assertSee('/'.$app.'/manifest.webmanifest', false);
        }
    }
}

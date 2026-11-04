<?php

namespace Tests\Feature\Restaurant;

use App\Models\Kassa\Employee;
use App\Models\Kassa\Sale;
use App\Models\Kassa\Store;
use App\Models\Restaurant\Category;
use App\Models\Restaurant\Dish;
use App\Models\Restaurant\Order;
use App\Models\Restaurant\Settings;
use App\Models\Restaurant\Table;
use App\Services\Restaurant\RestaurantDemoService;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Два варианта развёртывания из одной ветки: чистая установка (пусто, первый запуск — мастер с кодом установки)
 * и демонстрационная (данные только из restaurant:demo при KASSA_DEMO_ENABLED=true вне production).
 * Демо-данные не попадают в чистую установку ни при каких настройках по умолчанию.
 */
class CleanInstallTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_of_a_clean_install_keep_demo_off(): void
    {
        $env = file_get_contents(base_path('.env.example'));
        $this->assertMatchesRegularExpression('/^APP_ENV=production$/m', $env);
        $this->assertMatchesRegularExpression('/^KASSA_DEMO_ENABLED=false$/m', $env);
        $this->assertMatchesRegularExpression('/^DEPLOY_MODE=local$/m', $env);
        // без переменной в .env демо выключено
        $this->assertStringContainsString("env('KASSA_DEMO_ENABLED', false)", file_get_contents(base_path('config/kassa.php')));
        $this->assertFalse(RestaurantDemoService::allowed());

        // демо-команд нет в artisan
        $this->app->make(ConsoleKernel::class)->setArtisan(null);
        $commands = array_keys(Artisan::all());
        $this->assertNotContains('restaurant:demo', $commands);
        $this->assertNotContains('kassa:demo', $commands);
        $this->assertContains('restaurant:sync', $commands);
    }

    public function test_clean_install_is_empty_and_starts_with_the_setup_wizard(): void
    {
        // после миграций — ни ресторана, ни меню, ни столиков, ни сотрудников
        foreach ([Store::class, Category::class, Dish::class, Table::class, Order::class, Sale::class] as $model) {
            $this->assertSame(0, $model::withoutGlobalScopes()->count(), $model);
        }
        $this->assertSame(0, Employee::withoutGlobalScopes()->count());

        $options = $this->getJson('/api/restaurant/options?app=admin')->assertOk()
            ->assertJsonPath('needs_setup', true)->assertJsonPath('demo_hint', null);
        $this->assertSame([], $options->json('employees'));
        $this->getJson('/api/restaurant/options?app=cashier')->assertOk()->assertJsonPath('needs_setup', true);

        // демо через мастер или API недоступно
        $this->postJson('/api/kassa/setup', ['demo' => true])->assertNotFound();
        $this->assertSame(0, Dish::withoutGlobalScopes()->count());

        // мастер: ресторан и владелец; меню, столики и сотрудников создаёт владелец
        $setup = $this->postJson('/api/kassa/setup', ['store_name' => 'Чайхана «Бахор»', 'owner_name' => 'Рустам', 'pin' => '7391'])->assertCreated();
        $this->postJson('/api/kassa/setup', ['store_name' => 'Ещё', 'owner_name' => 'Кто-то', 'pin' => '7391'])->assertStatus(409);
        $this->withToken($setup->json('token'));
        $this->getJson('/api/restaurant/admin/menu')->assertOk()->assertJsonPath('categories', [])->assertJsonPath('dishes', []);
        $this->getJson('/api/restaurant/admin/tables')->assertOk()->assertJsonPath('tables', []);
        $this->getJson('/api/restaurant/admin/orders?filter=open')->assertOk()->assertJsonPath('orders', []);
        $this->getJson('/api/restaurant/admin/settings')->assertOk()
            ->assertJsonPath('store.name', 'Чайхана «Бахор»')
            ->assertJsonPath('settings.printer_mode', 'off')
            ->assertJsonPath('settings.terminal_enabled', false)
            ->assertJsonPath('settings.terminal_test', false);
        $this->postJson('/api/kassa/data/demo')->assertNotFound();
        $this->assertSame(['owner'], Employee::withoutGlobalScopes()->pluck('role')->all());
        $this->assertSame(0, Dish::withoutGlobalScopes()->count() + Table::withoutGlobalScopes()->count() + Order::withoutGlobalScopes()->count());
        $this->assertFalse(Settings::withoutGlobalScopes()->firstOrFail()->terminal_enabled);
    }

    public function test_setup_wizard_on_a_production_server_needs_the_setup_code(): void
    {
        $this->app['env'] = 'production';
        $this->getJson('/api/restaurant/options?app=admin')->assertJsonPath('setup_code_required', true)->assertJsonPath('setup_ready', false);
        $this->postJson('/api/kassa/setup', ['store_name' => 'Чайхана', 'owner_name' => 'Рустам', 'pin' => '7391'])->assertForbidden();

        config(['kassa.setup_code' => 'a1b2c3d4e5f6']);
        $this->postJson('/api/kassa/setup', ['store_name' => 'Чайхана', 'owner_name' => 'Рустам', 'pin' => '7391', 'setup_code' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('setup_code');
        $this->postJson('/api/kassa/setup', ['store_name' => 'Чайхана', 'owner_name' => 'Рустам', 'pin' => '7391', 'setup_code' => 'a1b2c3d4e5f6'])->assertCreated();

        // на рабочем сервере демо закрыто даже при KASSA_DEMO_ENABLED=true
        config(['kassa.demo_enabled' => true]);
        $this->assertFalse(RestaurantDemoService::allowed());
        $this->assertSame(0, Dish::withoutGlobalScopes()->count());
    }
}

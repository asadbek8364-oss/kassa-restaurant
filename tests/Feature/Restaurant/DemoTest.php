<?php

namespace Tests\Feature\Restaurant;

use App\Console\Commands\RestaurantDemoCommand;
use App\Models\Kassa\Employee;
use App\Models\Kassa\Sale;
use App\Models\Kassa\Store;
use App\Models\Restaurant\Category;
use App\Models\Restaurant\Dish;
use App\Models\Restaurant\Order;
use App\Models\Restaurant\Settings;
use App\Models\Restaurant\Table;
use App\Services\Restaurant\RestaurantDemoService;
use App\Support\Kassa\StoreContext;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Демо-ресторан — только отдельной командой и только вне production (как демо кассы). */
class DemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_is_off_by_default_and_never_in_production(): void
    {
        $this->assertFalse(RestaurantDemoService::allowed());
        $this->assertFalse((new RestaurantDemoCommand)->isEnabled());

        config(['kassa.demo_enabled' => true]);
        $this->app['env'] = 'production';
        $this->assertFalse((new RestaurantDemoCommand)->isEnabled());
        $this->expectException(\RuntimeException::class);
        app(RestaurantDemoService::class)->load();
    }

    public function test_demo_restaurant_is_created_by_its_own_command(): void
    {
        $existing = Store::create(['name' => 'Магазин аксессуаров']);
        config(['kassa.demo_enabled' => true]);
        $this->app->make(ConsoleKernel::class)->setArtisan(null);

        $this->artisan('restaurant:demo', ['--force' => true])->assertSuccessful();
        $this->artisan('restaurant:demo', ['--force' => true])->assertSuccessful(); // пересоздаётся, а не дублируется

        $this->assertSame(['Магазин аксессуаров', RestaurantDemoService::NAME], Store::orderBy('id')->pluck('name')->all());
        $demo = Store::where('name', RestaurantDemoService::NAME)->sole();
        $this->assertNotNull($existing->fresh());

        app(StoreContext::class)->run($demo, function () {
            $this->assertSame(22, Dish::count());
            $this->assertSame(5, Category::count());
            $this->assertSame(8, Table::count());
            $this->assertSame(['admin', 'cashier', 'cook', 'waiter'], Employee::whereNotNull('store_id')->orderBy('role')->pluck('role')->all());
            $this->assertSame(4, Order::whereIn('status', Order::OPEN)->where('type', 'table')->count());
            $this->assertSame(2, Order::whereIn('status', Order::OPEN)->where('type', 'takeaway')->count());
            $this->assertGreaterThan(50, Order::where('status', 'paid')->count());
            $this->assertGreaterThan(0, Order::where('status', 'paid')->where('type', 'takeaway')->count());
            // каждая продажа — оплаченный заказ (навынос, оплаченный заранее, ещё открыт)
            $this->assertSame(Order::whereNotNull('sale_id')->count(), Sale::count());
            $this->assertSame(1, Order::whereIn('status', Order::OPEN)->whereNotNull('sale_id')->count());
            $settings = Settings::current();
            $this->assertTrue($settings->terminal_enabled && $settings->terminal_test);
        });

        // все три приложения работают на демо-данных
        $admin = Employee::withoutGlobalScopes()->where('role', 'admin')->sole();
        $token = $this->postJson('/api/restaurant/login', ['employee_id' => $admin->id, 'pin' => '1111', 'app' => 'admin'])->assertOk()->json('token');
        $this->withToken($token);
        $this->assertGreaterThan(0, $this->getJson('/api/restaurant/admin/reports?from='.now()->subDays(13)->toDateString())->json('summary.revenue'));
        $this->assertCount(4, $this->getJson('/api/restaurant/waiter/state')->collect('tables')->whereNotNull('order'));
        $cards = $this->getJson('/api/restaurant/kitchen/board')->collect('cards');
        $this->assertSame(['cooking', 'cooking', 'new', 'ready', 'ready'], $cards->pluck('column')->sort()->values()->all());
        $this->assertSame(2, $cards->where('takeaway', true)->count());
        $this->getJson('/api/restaurant/options?app=waiter&store_id='.$admin->store_id)
            ->assertJsonPath('demo_hint', RestaurantDemoService::HINT);

        // кассир — PIN 4444: очередь навынос и оплата через эмулятор терминала
        $cashier = Employee::withoutGlobalScopes()->where('role', 'cashier')->sole();
        $login = $this->postJson('/api/restaurant/login', ['employee_id' => $cashier->id, 'pin' => '4444', 'app' => 'waiter'])->assertOk();
        $login->assertJsonPath('app', 'cashier')->assertJsonPath('home', '/cashier');
        $state = $this->withToken($login->json('token'))->getJson('/api/restaurant/cashier/state')->assertOk()
            ->assertJsonPath('terminal', ['enabled' => true, 'test' => true]);
        $this->assertSame(['cooking', 'ready'], $state->collect('takeaway')->pluck('status')->sort()->values()->all());
        $this->assertSame([true, false], $state->collect('takeaway')->sortBy('status')->pluck('paid')->values()->all());
    }
}

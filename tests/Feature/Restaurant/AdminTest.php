<?php

namespace Tests\Feature\Restaurant;

use App\Models\Restaurant\Order;
use App\Models\Restaurant\Table;
use App\Support\Kassa\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Админка: меню и стоп-лист, столики, сотрудники и PIN, настройки, отчёты. */
class AdminTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurant();
        $this->actAs($this->admin);
    }

    public function test_menu_categories_dishes_and_stop_list(): void
    {
        $soups = $this->postJson('/api/restaurant/admin/categories', ['name' => 'Супы'])->assertCreated()->json('category');
        $this->postJson('/api/restaurant/admin/categories', ['name' => 'супы'])->assertUnprocessable()->assertJsonValidationErrors('name');

        $dish = $this->postJson('/api/restaurant/admin/dishes', [
            'category_id' => $soups['id'], 'name' => 'Шурпа', 'price' => 38000, 'description' => 'Баранина, овощи, нут',
        ])->assertCreated()->assertJsonPath('dish.active', true)->assertJsonPath('dish.stop', false)->json('dish');
        $this->putJson("/api/restaurant/admin/dishes/{$dish['id']}", ['category_id' => $soups['id'], 'name' => 'Шурпа из баранины', 'price' => 39000])
            ->assertOk()->assertJsonPath('dish.price', 39000);

        // стоп-лист: официант видит блюдо, но заказать не может
        $this->postJson("/api/restaurant/admin/dishes/{$dish['id']}/stop", ['stop' => true])->assertOk()->assertJsonPath('dish.stop', true);
        $this->actAs($this->waiter);
        $menu = $this->getJson('/api/restaurant/waiter/menu')->assertOk();
        $this->assertTrue(collect($menu->json('dishes'))->firstWhere('name', 'Шурпа из баранины')['stop']);
        $this->openOrder($this->t1, [['dish_id' => $dish['id'], 'qty' => 1]])->assertUnprocessable();

        // стоп-лист можно снять и с кухни
        $this->actAs($this->cook);
        $this->postJson("/api/restaurant/kitchen/dishes/{$dish['id']}/stop", ['stop' => false])->assertOk();
        $this->getJson('/api/restaurant/kitchen/menu')->assertOk();

        $this->actAs($this->admin);
        $this->deleteJson("/api/restaurant/admin/categories/{$soups['id']}")->assertUnprocessable();
        $this->deleteJson("/api/restaurant/admin/dishes/{$dish['id']}")->assertOk();
        $this->assertSoftDeleted('restaurant_dishes', ['id' => $dish['id']]);
        $this->deleteJson("/api/restaurant/admin/categories/{$soups['id']}")->assertOk();
        $this->assertNotContains('Шурпа из баранины', array_column($this->getJson('/api/restaurant/admin/menu')->json('dishes'), 'name'));
    }

    public function test_tables(): void
    {
        $this->postJson('/api/restaurant/admin/tables', ['name' => 'VIP', 'seats' => 10])->assertCreated()->assertJsonPath('table.status', 'free');
        $this->postJson('/api/restaurant/admin/tables', ['name' => 'vip', 'seats' => 2])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/restaurant/admin/tables', ['name' => 'Терраса', 'seats' => 0])->assertUnprocessable()->assertJsonValidationErrors('seats');

        $this->putJson("/api/restaurant/admin/tables/{$this->t2->id}", ['name' => '2', 'seats' => 8, 'active' => false])->assertOk();
        $this->actAs($this->waiter);
        $this->assertSame(['1', 'VIP'], array_column($this->getJson('/api/restaurant/waiter/state')->json('tables'), 'name'));
        $this->openOrder($this->t1, [$this->line($this->tea)])->assertCreated();

        $this->actAs($this->admin);
        $this->deleteJson("/api/restaurant/admin/tables/{$this->t1->id}")->assertUnprocessable()->assertJsonValidationErrors('table_id');
        $this->assertSame('busy', collect($this->getJson('/api/restaurant/admin/tables')->json('tables'))->firstWhere('name', '1')['status']);
        $this->deleteJson("/api/restaurant/admin/tables/{$this->t2->id}")->assertOk();
        $this->assertSoftDeleted('restaurant_tables', ['id' => $this->t2->id]);
    }

    public function test_staff_with_pin_rules(): void
    {
        $this->postJson('/api/restaurant/admin/staff', ['name' => 'Мадина', 'role' => 'waiter', 'pin' => '1234'])
            ->assertUnprocessable()->assertJsonValidationErrors('pin');
        $this->postJson('/api/restaurant/admin/staff', ['name' => 'Мадина', 'role' => 'owner', 'pin' => '7391'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $id = $this->postJson('/api/restaurant/admin/staff', ['name' => 'Мадина Алиева', 'role' => 'waiter', 'pin' => '7391', 'phone' => '+998 94 220-61-48'])
            ->assertCreated()->assertJsonPath('employee.role_name', 'Официант')->json('employee.id');

        $token = $this->postJson('/api/restaurant/login', ['employee_id' => $id, 'pin' => '7391', 'app' => 'waiter'])->assertOk()->json('token');

        // смена PIN и отключение: отключённый сразу теряет вход
        $this->actAs($this->admin);
        $this->putJson("/api/restaurant/admin/staff/{$id}", ['name' => 'Мадина Алиева', 'role' => 'waiter', 'pin' => '4826'])->assertOk();
        $this->postJson("/api/restaurant/admin/staff/{$id}/toggle")->assertOk()->assertJsonPath('employee.active', false);
        $this->withToken($token)->getJson('/api/restaurant/waiter/state')->assertUnauthorized();
        $this->actAs($this->admin);
        $this->postJson("/api/restaurant/admin/staff/{$this->admin->id}/toggle")->assertUnprocessable();
        $this->assertSame(['Дилшод Каримов', 'Азиз Рахимов', 'Шахзода Юсупова', 'Мадина Алиева'], array_column($this->getJson('/api/restaurant/admin/staff')->json('staff'), 'name'));
    }

    public function test_restaurant_settings(): void
    {
        $this->putJson('/api/restaurant/admin/settings/restaurant', [
            'name' => 'Чайхана «Навруз»', 'address' => 'ул. Навои, 30', 'cook_minutes' => 20, 'receipt_footer' => 'Ждём снова!',
        ])->assertOk()->assertJsonPath('store.name', 'Чайхана «Навруз»')->assertJsonPath('settings.cook_minutes', 20);
        $this->putJson('/api/restaurant/admin/settings/restaurant', ['name' => 'X', 'cook_minutes' => 0])->assertUnprocessable();

        $this->actAs($this->cook);
        $this->getJson('/api/restaurant/kitchen/board')->assertJsonPath('cook_minutes', 20);
        $this->getJson('/api/restaurant/me')->assertJsonPath('store.name', 'Чайхана «Навруз»');
    }

    public function test_reports_by_day_waiter_table_and_dashboard(): void
    {
        $pay = function (Table $table, array $lines) {
            $this->actAs($this->waiter);
            $order = $this->openOrder($table, $lines)->assertCreated()->json('order');
            $this->actAs($this->cook);
            $this->postJson("/api/restaurant/kitchen/tickets/{$order['tickets'][0]['id']}/ready")->assertOk();
            $this->actAs($this->waiter);
            $this->postJson("/api/restaurant/waiter/orders/{$order['id']}/pay", ['payment' => 'card'])->assertOk();
        };

        $this->travelTo(Clock::today()->subDays(2)->setTime(13, 0));
        $pay($this->t1, [$this->line($this->plov, 2)]);                 // 90 000
        $this->travelBack();
        $pay($this->t2, [$this->line($this->lagman), $this->line($this->tea, 2)]); // 56 000
        $pay($this->t1, [$this->line($this->plov)]);                    // 45 000

        $this->actAs($this->waiter);
        $this->openOrder($this->t2, [$this->line($this->tea)])->assertCreated(); // открытый — не в выручке

        $this->actAs($this->admin);
        $report = $this->getJson('/api/restaurant/admin/reports?from='.Clock::today()->subDays(2)->toDateString().'&to='.Clock::today()->toDateString())->assertOk();
        $report->assertJsonPath('summary.revenue', 191000)->assertJsonPath('summary.orders', 3)->assertJsonPath('summary.avg', 63667);
        $this->assertSame([90000, 0, 101000], array_column($report->json('by_day'), 'revenue'));
        $this->assertSame([['1', 135000, 2], ['2', 56000, 1]], array_map(fn ($r) => [$r['name'], $r['revenue'], $r['orders']], $report->json('by_table')));
        $this->assertSame(191000, $report->json('by_waiter.0.revenue'));
        $this->assertSame(['Плов чайханский', 3], [$report->json('top_dishes.0.name'), $report->json('top_dishes.0.qty')]);
        $this->assertSame(191000, collect($report->json('by_payment'))->firstWhere('payment', 'card')['revenue']);

        $this->getJson('/api/restaurant/admin/reports?from=2026-01-10&to=2026-01-01')->assertUnprocessable();
        $dashboard = $this->getJson('/api/restaurant/admin/dashboard')->assertOk()
            ->assertJsonPath('today.revenue', 101000)
            ->assertJsonPath('tables.busy', 1)
            ->assertJsonPath('kitchen.new', 1);
        $this->assertCount(1, $dashboard->json('open_orders'));
        $this->assertCount(3, $this->getJson('/api/restaurant/admin/orders?filter=day')->json('orders'));
        $this->assertCount(1, $this->getJson('/api/restaurant/admin/orders?filter=open')->json('orders'));
        $this->assertSame(1, Order::whereIn('status', Order::OPEN)->count());
    }
}

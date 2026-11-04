<?php

namespace Tests\Feature\Restaurant;

use App\Models\Kassa\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Роль «Кассир»: общий PIN-вход, редирект по роли, свои права доступа, своё приложение /cashier. */
class CashierTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurant();
    }

    public function test_cashier_logs_in_by_pin_and_is_redirected_to_the_cashier_app(): void
    {
        $login = fn (Employee $e, string $pin, string $app) => $this->postJson('/api/restaurant/login', ['employee_id' => $e->id, 'pin' => $pin, 'app' => $app]);

        $login($this->cashier, '4862', 'cashier')->assertOk()->assertJsonPath('app', 'cashier')->assertJsonPath('redirect', false);
        // кассир ввёл PIN на планшете официанта или на экране кухни — его отправляют в приложение кассира
        $login($this->cashier, '4862', 'waiter')->assertOk()->assertJsonPath('app', 'cashier')->assertJsonPath('home', '/cashier')->assertJsonPath('redirect', true);
        $token = $login($this->cashier, '4862', 'kitchen')->assertOk()->assertJsonPath('home', '/cashier')->json('token');
        // официант на экране кассира — в своё приложение; администратор может открыть и кассу ресторана
        $login($this->waiter, '2846', 'cashier')->assertOk()->assertJsonPath('app', 'waiter')->assertJsonPath('redirect', true);
        $login($this->admin, '5728', 'cashier')->assertOk()->assertJsonPath('app', 'cashier')->assertJsonPath('redirect', false);

        $this->withToken($token)->getJson('/api/restaurant/me')->assertOk()
            ->assertJsonPath('employee.role', 'cashier')
            ->assertJsonPath('employee.role_name', 'Кассир')
            ->assertJsonPath('apps', [['app' => 'cashier', 'url' => '/cashier', 'name' => 'приложение кассира']])
            ->assertJsonPath('settings.terminal.enabled', false);
    }

    public function test_cashier_login_screen_lists_cashiers_first(): void
    {
        $names = array_column($this->getJson('/api/restaurant/options?app=cashier')->assertOk()->json('employees'), 'name');

        $this->assertSame(['Малика Турсунова', 'Дилшод Каримов'], $names);
        $this->assertNotContains('Малика Турсунова', array_column($this->getJson('/api/restaurant/options?app=waiter')->json('employees'), 'name'));
    }

    public function test_cashier_has_access_only_to_the_cashier_app(): void
    {
        $this->actAs($this->cashier);
        $this->getJson('/api/restaurant/cashier/state')->assertOk()->assertJsonPath('terminal.enabled', false);
        $this->getJson('/api/restaurant/cashier/menu')->assertOk();
        $this->getJson('/api/restaurant/waiter/state')->assertForbidden()->assertJsonPath('home', '/cashier');
        $this->getJson('/api/restaurant/kitchen/board')->assertForbidden()->assertJsonPath('home', '/cashier');
        $this->getJson('/api/restaurant/admin/dashboard')->assertForbidden()->assertJsonPath('home', '/cashier');
        $this->postJson('/api/restaurant/admin/staff', ['name' => 'Я', 'role' => 'admin', 'pin' => '7391'])->assertForbidden();
        // розничная касса и склад — не для кассира ресторана
        $this->getJson('/api/kassa/bootstrap')->assertForbidden()->assertJsonPath('home', '/cashier');
        $this->postJson('/api/kassa/login', ['employee_id' => $this->cashier->id, 'pin' => '4862'])
            ->assertUnprocessable()->assertJsonPath('errors.employee_id.0', 'Ваш интерфейс — приложение кассира: /cashier');

        // официант и повар в приложение кассира не попадают, администратор — да
        $this->actAs($this->waiter);
        $this->getJson('/api/restaurant/cashier/state')->assertForbidden()->assertJsonPath('home', '/waiter');
        $this->postJson('/api/restaurant/cashier/takeaway', ['items' => [$this->line($this->tea)]])->assertForbidden();
        $this->actAs($this->cook);
        $this->getJson('/api/restaurant/cashier/state')->assertForbidden()->assertJsonPath('home', '/kitchen');
        $this->actAs($this->admin);
        $this->getJson('/api/restaurant/cashier/state')->assertOk();
    }

    public function test_cashier_works_with_table_orders_like_a_waiter(): void
    {
        $this->actAs($this->waiter);
        $order = $this->openOrder($this->t1, [$this->line($this->plov)])->assertCreated()->json('order');

        $this->actAs($this->cashier);
        $state = $this->getJson('/api/restaurant/cashier/state')->assertOk();
        $this->assertSame('busy', collect($state->json('tables'))->firstWhere('id', $this->t1->id)['status']);
        $this->mark($order, 'ready', [], 'cashier')->assertOk()->assertJsonPath('order.status', 'ready');
        $this->postJson("/api/restaurant/cashier/orders/{$order['id']}/bill", ['print' => false])->assertOk();
        $this->postJson("/api/restaurant/cashier/orders/{$order['id']}/pay", ['payment' => 'cash', 'received' => 50000])
            ->assertOk()->assertJsonPath('order.status', 'paid')->assertJsonPath('order.change', 5000);
        $this->assertSame('free', collect($this->getJson('/api/restaurant/cashier/state')->json('tables'))->firstWhere('id', $this->t1->id)['status']);
    }

    public function test_admin_manages_cashiers_in_staff(): void
    {
        $this->actAs($this->admin);
        $this->postJson('/api/restaurant/admin/staff', ['name' => 'Нодира Каримова', 'role' => 'cashier', 'pin' => '7391'])
            ->assertCreated()->assertJsonPath('employee.role', 'cashier')->assertJsonPath('employee.role_name', 'Кассир');
        $this->postJson('/api/restaurant/admin/staff', ['name' => 'Кто-то', 'role' => 'owner', 'pin' => '7391'])->assertUnprocessable();
        $roles = collect($this->getJson('/api/restaurant/admin/staff')->json('staff'))->pluck('role')->all();
        $this->assertSame(['admin', 'cashier', 'cashier', 'waiter', 'cook'], $roles);
    }

    public function test_cashier_app_has_its_own_page_layout_and_pwa(): void
    {
        $page = $this->get('/cashier')->assertOk()
            ->assertSee('<body class="app-cashier"', false)
            ->assertSee('/cashier/manifest.webmanifest', false)
            ->assertSee('id="tabs"', false);
        $html = $page->getContent();
        // общий модуль зала подключается раньше файла приложения
        $this->assertMatchesRegularExpression('~/restaurant/common\.js\?v=\w+"></script>\s*<script src="/restaurant/floor\.js\?v=\w+"></script>\s*<script src="/restaurant/cashier\.js~', $html);

        $this->get('/cashier/manifest.webmanifest')->assertOk()
            ->assertJsonPath('id', '/cashier')->assertJsonPath('start_url', '/cashier')->assertJsonPath('scope', '/cashier')
            ->assertJsonPath('short_name', 'Кассир')
            ->assertJsonPath('icons.0.src', '/restaurant/icons/cashier.svg');
        $sw = $this->get('/cashier/sw.js')->assertOk()->assertHeader('Service-Worker-Allowed', '/cashier');
        $this->assertStringContainsString('floor.js?v=', $sw->getContent()); // общий модуль зала — в оболочке офлайн
        $this->assertStringContainsString('restoran-cashier-', $sw->getContent());
        $this->assertFileExists(public_path('restaurant/icons/cashier-192.png'));
        $this->assertFileExists(public_path('restaurant/icons/cashier-512.png'));
    }
}

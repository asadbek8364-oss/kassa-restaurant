<?php

namespace Tests\Feature\Restaurant;

use App\Models\Kassa\Employee;
use App\Models\Kassa\Store;
use App\Models\Restaurant\Category;
use App\Models\Restaurant\Dish;
use App\Support\Kassa\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Доступ по ролям: один PIN-вход, роль определяет приложение; официант и повар не попадают в чужие интерфейсы. */
class RoleAccessTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurant();
    }

    public function test_login_by_pin_opens_the_interface_of_the_role(): void
    {
        $login = fn (Employee $e, string $pin, string $app) => $this->postJson('/api/restaurant/login', ['employee_id' => $e->id, 'pin' => $pin, 'app' => $app]);

        $login($this->waiter, '0000', 'waiter')->assertUnprocessable()->assertJsonPath('errors.pin.0', 'Неверный PIN');
        $login($this->waiter, '2846', 'waiter')->assertOk()->assertJsonPath('app', 'waiter')->assertJsonPath('redirect', false);
        // официант вошёл на экране админки — его отправляют в приложение официанта
        $login($this->waiter, '2846', 'admin')->assertOk()->assertJsonPath('app', 'waiter')->assertJsonPath('home', '/waiter')->assertJsonPath('redirect', true);
        $login($this->cook, '3957', 'waiter')->assertOk()->assertJsonPath('app', 'kitchen')->assertJsonPath('home', '/kitchen');
        // администратор может открыть и приложение официанта
        $login($this->admin, '5728', 'waiter')->assertOk()->assertJsonPath('app', 'waiter')->assertJsonPath('redirect', false);
        $token = $login($this->admin, '5728', 'admin')->assertOk()->assertJsonPath('app', 'admin')->json('token');

        $this->withToken($token)->getJson('/api/restaurant/me')->assertOk()
            ->assertJsonPath('employee.role', 'admin')
            ->assertJsonPath('mode', 'local')
            ->assertJsonPath('apps.0.app', 'admin');
    }

    public function test_login_screen_lists_staff_of_the_application(): void
    {
        $names = fn (string $app) => array_column($this->getJson('/api/restaurant/options?app='.$app)->assertOk()->json('employees'), 'name');

        $this->assertSame(['Азиз Рахимов', 'Дилшод Каримов'], $names('waiter'));
        $this->assertSame(['Шахзода Юсупова', 'Дилшод Каримов'], $names('kitchen'));
        $this->assertSame(['Дилшод Каримов'], $names('admin'));
        $this->getJson('/api/restaurant/options?app=kassa')->assertUnprocessable();
    }

    public function test_waiter_and_cook_cannot_open_foreign_interfaces(): void
    {
        $this->actAs($this->waiter);
        $this->getJson('/api/restaurant/admin/dashboard')->assertForbidden()->assertJsonPath('home', '/waiter');
        $this->getJson('/api/restaurant/admin/menu')->assertForbidden();
        $this->postJson('/api/restaurant/admin/staff', ['name' => 'Я', 'role' => 'admin', 'pin' => '7391'])->assertForbidden();
        $this->getJson('/api/restaurant/kitchen/board')->assertForbidden()->assertJsonPath('home', '/waiter');
        $this->getJson('/api/restaurant/waiter/state')->assertOk();

        $this->actAs($this->cook);
        $this->getJson('/api/restaurant/admin/reports')->assertForbidden()->assertJsonPath('home', '/kitchen');
        $this->getJson('/api/restaurant/waiter/state')->assertForbidden();
        $this->openOrder($this->t1, [$this->line($this->plov)])->assertForbidden();
        $this->getJson('/api/restaurant/kitchen/board')->assertOk();

        $this->actAs($this->admin);
        $this->getJson('/api/restaurant/admin/dashboard')->assertOk();
        $this->getJson('/api/restaurant/waiter/state')->assertOk();
        $this->getJson('/api/restaurant/kitchen/board')->assertOk();

        $this->withoutToken()->getJson('/api/restaurant/waiter/state')->assertUnauthorized();
    }

    public function test_waiter_and_cook_have_no_access_to_the_kassa(): void
    {
        $this->actAs($this->waiter);
        $this->getJson('/api/kassa/bootstrap')->assertForbidden()->assertJsonPath('home', '/waiter');
        $this->postJson('/api/kassa/sales', ['items' => [], 'payment' => 'cash'])->assertForbidden();

        // в кассе нет официантов и поваров на экране входа, PIN-вход в кассу им закрыт
        $this->assertSame(['Дилшод Каримов'], array_column($this->getJson('/api/kassa/options')->json('employees'), 'name'));
        $this->postJson('/api/kassa/login', ['employee_id' => $this->cook->id, 'pin' => '3957'])
            ->assertUnprocessable()->assertJsonPath('errors.employee_id.0', 'Ваш интерфейс — экран кухни: /kitchen');

        // администратор ресторана ведёт склад в кассе
        $this->postJson('/api/kassa/login', ['employee_id' => $this->admin->id, 'pin' => '5728'])->assertOk();
    }

    public function test_restaurants_are_isolated(): void
    {
        $other = Store::create(['name' => 'Второй ресторан']);
        $foreign = app(StoreContext::class)->run($other, function () {
            $c = Category::create(['name' => 'Чужое']);

            return Dish::create(['category_id' => $c->id, 'name' => 'Чужой плов', 'price' => 1]);
        });

        $this->actAs($this->admin);
        $this->assertNotContains('Чужой плов', array_column($this->getJson('/api/restaurant/admin/menu')->json('dishes'), 'name'));
        $this->putJson("/api/restaurant/admin/dishes/{$foreign->id}", ['category_id' => $this->hot->id, 'name' => 'X', 'price' => 1])->assertNotFound();
        $this->actAs($this->waiter);
        $this->openOrder($this->t1, [['dish_id' => $foreign->id, 'qty' => 1]])->assertUnprocessable();
    }

    public function test_each_app_has_its_own_page_layout_and_pwa(): void
    {
        foreach (['admin' => 'Админка', 'waiter' => 'Официант', 'cashier' => 'Кассир', 'kitchen' => 'Кухня'] as $app => $short) {
            $this->get('/'.$app)->assertOk()
                ->assertSee('<body class="app-'.$app.'"', false)
                ->assertSee('/'.$app.'/manifest.webmanifest', false)
                ->assertSee('/restaurant/'.$app.'.js', false);

            $manifest = $this->get('/'.$app.'/manifest.webmanifest')->assertOk()->assertHeader('Content-Type', 'application/manifest+json');
            $manifest->assertJsonPath('id', '/'.$app)->assertJsonPath('start_url', '/'.$app)->assertJsonPath('scope', '/'.$app)
                ->assertJsonPath('short_name', $short);

            $sw = $this->get('/'.$app.'/sw.js')->assertOk()->assertHeader('Service-Worker-Allowed', '/'.$app);
            $this->assertStringStartsWith('application/javascript', $sw->headers->get('Content-Type'));
            $this->assertStringContainsString('restoran-'.$app.'-', $sw->getContent());
        }
        // кухня — пассивный настенный дисплей: на весь экран, светлая тема, без кнопок
        $this->get('/kitchen/manifest.webmanifest')->assertJsonPath('display', 'fullscreen')->assertJsonPath('theme_color', '#ffffff');
        $kitchen = $this->get('/kitchen')->getContent();
        $layout = substr($kitchen, strpos($kitchen, '<body'), strpos($kitchen, 'id="login"') - strpos($kitchen, '<body'));
        $this->assertStringNotContainsString('<button', $layout);
        $this->assertStringNotContainsString('floor.js', $kitchen);
    }
}

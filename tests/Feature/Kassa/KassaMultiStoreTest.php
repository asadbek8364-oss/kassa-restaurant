<?php

namespace Tests\Feature\Kassa;

use App\Models\Kassa\Employee;
use App\Models\Kassa\Product;
use App\Models\Kassa\Sale;
use App\Models\Kassa\Store;
use App\Models\Kassa\Token;
use App\Support\Kassa\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Несколько магазинов: создание, переключение, изоляция данных, сводка владельца. */
class KassaMultiStoreTest extends TestCase
{
    use KassaHelpers;
    use RefreshDatabase;

    private Store $a;

    private Store $b;

    private Employee $owner;

    private Employee $sellerA;

    private Employee $sellerB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = Store::create(['name' => 'Юнусабад']);
        $this->b = Store::create(['name' => 'Чиланзар']);
        $this->owner = Employee::create(['name' => 'Владелец', 'role' => 'owner', 'pin' => '5728']);
        $this->sellerA = $this->inStore($this->a, fn () => Employee::create(['name' => 'Азиз', 'role' => 'seller', 'pin' => '2846']));
        $this->sellerB = $this->inStore($this->b, fn () => Employee::create(['name' => 'Мадина', 'role' => 'seller', 'pin' => '3957']));
    }

    public function test_owner_creates_a_store_and_switches_to_it(): void
    {
        $this->loginAs($this->owner, '5728', $this->a);
        $this->getJson('/api/kassa/bootstrap')->assertOk()
            ->assertJsonPath('store.id', $this->a->id)
            ->assertJsonCount(2, 'stores');

        $this->postJson('/api/kassa/stores', ['name' => 'юнусабад'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $id = $this->postJson('/api/kassa/stores', ['name' => 'Сергели', 'address' => 'ул. Янги Сергели, 5'])
            ->assertCreated()->assertJsonPath('store.name', 'Сергели')->json('store.id');

        $this->postJson("/api/kassa/stores/{$id}/switch")->assertOk()->assertJsonPath('store.id', $id);
        $this->getJson('/api/kassa/bootstrap')->assertOk()
            ->assertJsonPath('store.name', 'Сергели')
            ->assertJsonCount(0, 'products')
            ->assertJsonCount(3, 'stores');
        // в новом магазине — только владелец, продавцов других магазинов не видно
        $this->assertSame([$this->owner->id], array_column($this->getJson('/api/kassa/employees')->json('employees'), 'id'));
    }

    public function test_products_and_documents_are_isolated_between_stores(): void
    {
        $pa = $this->makeProduct(['name' => 'Кабель А', 'stock' => 5], $this->a);
        $pb = $this->makeProduct(['name' => 'Кабель Б', 'barcode' => $pa->barcode, 'stock' => 7], $this->b);

        $this->loginAs($this->sellerA, '2846');
        $this->assertSame(['Кабель А'], array_column($this->getJson('/api/kassa/products')->json('products'), 'name'));
        $this->getJson("/api/kassa/products/{$pb->id}")->assertNotFound();
        $this->putJson("/api/kassa/products/{$pb->id}", ['name' => 'Чужой', 'category' => 'cables', 'buy_price' => 1, 'sell_price' => 2, 'min_stock' => 0])->assertNotFound();
        // чужой товар нельзя продать: для этого магазина его нет
        $this->postJson('/api/kassa/sales', ['items' => [['product_id' => $pb->id, 'qty' => 1]], 'payment' => 'card'])
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $saleA = $this->postJson('/api/kassa/sales', ['items' => [['product_id' => $pa->id, 'qty' => 2]], 'payment' => 'card'])
            ->assertCreated()->assertJsonPath('sale.number', 1001)->json('sale');

        $this->loginAs($this->sellerB, '3957');
        $this->getJson('/api/kassa/sales/'.$saleA['id'])->assertNotFound();
        $this->getJson('/api/kassa/sales')->assertOk()->assertJsonCount(0, 'sales');
        $this->getJson('/api/kassa/journal')->assertOk()->assertJsonPath('counts.all', 0);
        $this->getJson('/api/kassa/dashboard')->assertOk()->assertJsonPath('today.checks', 0);
        $this->postJson('/api/kassa/returns', ['sale_id' => $saleA['id'], 'items' => [['sale_item_id' => $saleA['items'][0]['id'], 'qty' => 1]], 'reason' => 'Брак'])
            ->assertUnprocessable()->assertJsonValidationErrors('sale_id');
        // своя нумерация чеков в каждом магазине
        $this->postJson('/api/kassa/sales', ['items' => [['product_id' => $pb->id, 'qty' => 1]], 'payment' => 'qr'])
            ->assertCreated()->assertJsonPath('sale.number', 1001);
        $this->postJson('/api/kassa/incomes', ['items' => [['product_id' => $pb->id, 'qty' => 3, 'cost' => 9000]]])
            ->assertCreated()->assertJsonPath('income.number', 1);

        $this->assertSame(3, $pa->fresh()->stock);
        $this->assertSame(9, Product::withoutGlobalScopes()->find($pb->id)->stock);
        $this->assertSame(2, Sale::withoutGlobalScopes()->count());
    }

    /**
     * Магазин выбирается по токену раньше, чем модель из маршрута ищется в базе:
     * на рабочем сервере каждый запрос начинается без выбранного магазина.
     */
    public function test_route_models_are_resolved_in_the_store_of_the_token(): void
    {
        $pa = $this->makeProduct(['name' => 'Товар А'], $this->a);
        $pb = $this->makeProduct(['name' => 'Товар Б'], $this->b);
        $tokenA = $this->loginAs($this->sellerA, '2846');
        $tokenB = $this->loginAs($this->sellerB, '3957');

        app(StoreContext::class)->forget(); // как новый процесс PHP: по умолчанию был бы первый магазин (А)
        $this->withToken($tokenB)->getJson("/api/kassa/products/{$pb->id}")->assertOk()->assertJsonPath('product.name', 'Товар Б');
        $this->withToken($tokenB)->getJson("/api/kassa/products/{$pa->id}")->assertNotFound();

        $this->withToken($tokenA)->getJson("/api/kassa/products/{$pa->id}")->assertOk()->assertJsonPath('product.name', 'Товар А');
        $this->withToken($tokenA)->getJson("/api/kassa/products/{$pb->id}")->assertNotFound();
    }

    public function test_seller_always_works_in_own_store_and_cannot_manage_stores(): void
    {
        $this->makeProduct(['name' => 'Только в Б'], $this->b);
        $this->postJson('/api/kassa/login', ['employee_id' => $this->sellerB->id, 'pin' => '3957', 'store_id' => $this->a->id])
            ->assertOk()->assertJsonPath('store.id', $this->b->id);
        $this->loginAs($this->sellerB, '3957', $this->a);
        $this->getJson('/api/kassa/bootstrap')->assertOk()
            ->assertJsonPath('store.id', $this->b->id)
            ->assertJsonPath('products.0.name', 'Только в Б')
            ->assertJsonCount(1, 'stores');

        $this->getJson('/api/kassa/stores')->assertForbidden();
        $this->postJson('/api/kassa/stores', ['name' => 'Мой'])->assertForbidden();
        $this->postJson("/api/kassa/stores/{$this->a->id}/switch")->assertForbidden();
        $this->assertSame($this->b->id, Token::latest('id')->first()->store_id);
    }

    public function test_login_screen_shows_employees_of_the_selected_store(): void
    {
        $names = fn ($res) => array_column($res->json('employees'), 'name');

        $a = $this->getJson('/api/kassa/options?store_id='.$this->a->id)->assertOk()
            ->assertJsonPath('store.name', 'Юнусабад')->assertJsonCount(2, 'stores');
        $this->assertSame(['Владелец', 'Азиз'], $names($a));

        $b = $this->getJson('/api/kassa/options?store_id='.$this->b->id)->assertOk()->assertJsonPath('store.name', 'Чиланзар');
        $this->assertSame(['Владелец', 'Мадина'], $names($b));

        $this->getJson('/api/kassa/options?store_id=999')->assertOk()->assertJsonPath('store.id', $this->a->id);
    }

    public function test_employees_belong_to_their_store_and_owners_to_all(): void
    {
        $this->loginAs($this->owner, '5728', $this->b);
        $id = $this->postJson('/api/kassa/employees', ['name' => 'Сардор', 'role' => 'seller', 'pin' => '7391'])->assertCreated()->json('employee.id');
        $this->assertSame($this->b->id, Employee::withoutGlobalScopes()->find($id)->store_id);
        $this->assertEqualsCanonicalizing(['Владелец', 'Мадина', 'Сардор'], array_column($this->getJson('/api/kassa/employees')->json('employees'), 'name'));
        $this->getJson("/api/kassa/employees/{$this->sellerA->id}")->assertNotFound();

        // продавец, ставший владельцем, работает во всех магазинах
        $this->putJson("/api/kassa/employees/{$id}", ['name' => 'Сардор', 'role' => 'owner'])->assertOk();
        $this->assertNull(Employee::withoutGlobalScopes()->find($id)->store_id);
        $this->postJson("/api/kassa/stores/{$this->a->id}/switch")->assertOk();
        $this->assertContains('Сардор', array_column($this->getJson('/api/kassa/employees')->json('employees'), 'name'));
    }

    public function test_owner_sees_summary_across_all_stores(): void
    {
        $pa = $this->makeProduct(['sell_price' => 30000, 'buy_price' => 10000, 'stock' => 10], $this->a);
        $pb = $this->makeProduct(['sell_price' => 50000, 'buy_price' => 20000, 'stock' => 4, 'min_stock' => 5], $this->b);

        $this->loginAs($this->sellerA, '2846');
        $this->postJson('/api/kassa/sales', ['items' => [['product_id' => $pa->id, 'qty' => 2]], 'payment' => 'cash'])->assertCreated();
        $this->loginAs($this->sellerB, '3957');
        $this->postJson('/api/kassa/sales', ['items' => [['product_id' => $pb->id, 'qty' => 1]], 'payment' => 'card'])->assertCreated();

        $this->loginAs($this->owner, '5728', $this->a);
        $res = $this->getJson('/api/kassa/stores?period=today')->assertOk()
            ->assertJsonPath('stores.0.name', 'Юнусабад')
            ->assertJsonPath('stores.0.current', true)
            ->assertJsonPath('stores.0.summary.revenue', 60000)
            ->assertJsonPath('stores.1.summary.revenue', 50000)
            ->assertJsonPath('stores.1.low_stock', 1)
            ->assertJsonPath('total.revenue', 110000)
            ->assertJsonPath('total.checks', 2)
            ->assertJsonPath('total.profit', (60000 - 20000) + (50000 - 20000))
            ->assertJsonPath('total.warehouse_cost', 8 * 10000 + 3 * 20000);
        $this->assertSame(2, $res->json('total.stores'));
        $this->getJson('/api/kassa/stores?period=year')->assertUnprocessable();

        // сводка не сбивает текущий магазин владельца
        $this->getJson('/api/kassa/dashboard')->assertOk()->assertJsonPath('today.revenue', 60000);
    }

    public function test_store_wipe_and_settings_touch_only_the_current_store(): void
    {
        $pa = $this->makeProduct([], $this->a);
        $this->makeProduct([], $this->b);
        config(['kassa.demo_enabled' => true]);

        $this->loginAs($this->owner, '5728', $this->b);
        $this->putJson('/api/kassa/store', ['name' => 'Чиланзар-2'])->assertOk()->assertJsonPath('store.id', $this->b->id);
        $this->postJson('/api/kassa/data/wipe')->assertOk();

        $this->assertSame('Чиланзар-2', $this->b->fresh()->name);
        $this->assertSame('Юнусабад', $this->a->fresh()->name);
        $this->assertSame([$pa->id], Product::withoutGlobalScopes()->pluck('id')->all());
    }
}

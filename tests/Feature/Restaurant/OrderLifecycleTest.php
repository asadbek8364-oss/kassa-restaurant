<?php

namespace Tests\Feature\Restaurant;

use App\Models\Kassa\Sale;
use App\Models\Restaurant\KitchenTicket;
use App\Models\Restaurant\Order;
use App\Models\Restaurant\PrintJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Жизненный цикл заказа: официант → кухня (только показывает) → готовится → готово → подано → счёт → оплата →
 * продажи и отчёты. Отметки статусов ставят официант, кассир и админка — экран кухни не нужен.
 */
class OrderLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurant();
    }

    public function test_full_order_lifecycle_from_waiter_to_kitchen_to_payment(): void
    {
        // официант открывает заказ на столик 1 и отправляет на кухню
        $this->actAs($this->waiter);
        $res = $this->openOrder($this->t1, [$this->line($this->plov, 2, 'без лука'), $this->line($this->tea)], ['comment' => 'аллергия на орехи', 'guests' => 3])
            ->assertCreated()
            ->assertJsonPath('order.number', 1)
            ->assertJsonPath('order.status', 'new')
            ->assertJsonPath('order.total', 98000)
            ->assertJsonPath('order.waiter.name', 'Азиз Рахимов')
            ->assertJsonPath('order.items.0.comment', 'без лука');
        $orderId = $res->json('order.id');
        $this->assertSame('busy', $this->tableState($this->t1)['status']);

        // кухня видит заказ в колонке «Новые»: номер, столик, официант, время, позиции и комментарии
        $this->actAs($this->cook);
        $board = $this->board();
        $this->assertCount(1, $board['new']);
        $card = $board['new'][0];
        $this->assertSame(1, $card['number']);
        $this->assertSame('1', $card['table_name']);
        $this->assertSame('Азиз Рахимов', $card['waiter']);
        $this->assertSame('аллергия на орехи', $card['comment']);
        $this->assertNotNull($card['sent_at']);
        $this->assertSame(['Плов чайханский', 'Чай зелёный'], array_column($card['items'], 'name'));
        $this->assertSame(15, $this->getJson('/api/restaurant/kitchen/board')->json('cook_minutes'));

        // кухня не ставит отметок: «готовится» и «готово» отмечает официант, доска кухни это показывает
        $this->actAs($this->waiter);
        $this->mark(['id' => $orderId], 'cooking', ['ticket_id' => $card['id']])->assertOk()->assertJsonPath('order.status', 'cooking');
        $this->actAs($this->cook);
        $this->assertCount(1, $this->board()['cooking']);
        $this->actAs($this->waiter);
        $this->mark(['id' => $orderId], 'ready')->assertOk()->assertJsonPath('order.status', 'ready');
        $this->actAs($this->cook);
        $this->assertCount(1, $this->board()['ready']);

        // официант видит готовность у себя, подаёт, выдаёт счёт
        $this->actAs($this->waiter);
        $this->assertSame(3, $this->tableState($this->t1)['order']['ready_count']);
        $this->postJson("/api/restaurant/waiter/orders/{$orderId}/serve")->assertOk()->assertJsonPath('order.status', 'served');
        $bill = $this->postJson("/api/restaurant/waiter/orders/{$orderId}/bill", ['print' => false])->assertOk();
        $this->assertSame('bill', $this->tableState($this->t1)['status']);
        $receipt = collect($bill->json('receipt.lines'));
        $this->assertTrue($receipt->contains(fn ($l) => ($l['left'] ?? null) === 'ИТОГО' && $l['right'] === '98 000'));

        // оплата наличными: сдача, заказ закрыт, столик свободен, продажа в кассе
        $this->postJson("/api/restaurant/waiter/orders/{$orderId}/pay", ['payment' => 'cash', 'received' => 100000])
            ->assertOk()
            ->assertJsonPath('order.status', 'paid')
            ->assertJsonPath('order.change', 2000)
            ->assertJsonPath('order.payment', 'cash');
        $this->assertSame('free', $this->tableState($this->t1)['status']);
        $sale = Sale::with('items')->sole();
        $this->assertSame(98000, $sale->total);
        $this->assertSame('cash', $sale->payment);
        $this->assertSame(2000, $sale->change_amount);
        $this->assertSame($this->waiter->id, $sale->employee_id);
        $this->assertStringContainsString('Стол 1', $sale->customer);
        $this->assertSame([2, 1], $sale->items->pluck('qty')->all());
        $this->assertSame($sale->id, Order::find($orderId)->sale_id);

        // повторная оплата не создаёт вторую продажу
        $this->postJson("/api/restaurant/waiter/orders/{$orderId}/pay", ['payment' => 'cash'])->assertOk()->assertJsonPath('replay', true);
        $this->assertSame(1, Sale::count());

        // заказ в отчётах ресторана
        $this->actAs($this->admin);
        $report = $this->getJson('/api/restaurant/admin/reports')->assertOk();
        $report->assertJsonPath('summary.revenue', 98000)
            ->assertJsonPath('summary.orders', 1)
            ->assertJsonPath('summary.avg', 98000)
            ->assertJsonPath('summary.guests', 3)
            ->assertJsonPath('by_waiter.0.name', 'Азиз Рахимов')
            ->assertJsonPath('by_table.0.name', '1')
            ->assertJsonPath('top_dishes.0.name', 'Плов чайханский')
            ->assertJsonPath('top_dishes.0.qty', 2);
        $this->assertSame(98000, collect($report->json('by_day'))->last()['revenue']);
        $this->getJson('/api/restaurant/admin/dashboard')->assertOk()->assertJsonPath('today.revenue', 98000);
    }

    public function test_stop_listed_and_hidden_dishes_cannot_be_ordered(): void
    {
        $this->plov->update(['stop' => true]);
        $this->lagman->update(['active' => false]);
        $this->actAs($this->waiter);

        $this->openOrder($this->t1, [$this->line($this->plov)])->assertUnprocessable()
            ->assertJsonPath('errors.items.0', '«Плов чайханский» в стоп-листе — уберите его из заказа');
        $this->openOrder($this->t1, [$this->line($this->lagman)])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame(0, Order::count());

        $menu = $this->getJson('/api/restaurant/waiter/menu')->assertOk();
        $this->assertNotContains('Лагман', array_column($menu->json('dishes'), 'name'));
        $this->assertTrue(collect($menu->json('dishes'))->firstWhere('name', 'Плов чайханский')['stop']);
    }

    public function test_sending_twice_is_idempotent_and_busy_table_conflicts(): void
    {
        $this->actAs($this->waiter);
        $uuid = (string) Str::uuid();
        $first = $this->openOrder($this->t1, [$this->line($this->plov)], ['client_uuid' => $uuid])->assertCreated();
        $this->openOrder($this->t1, [$this->line($this->plov)], ['client_uuid' => $uuid])
            ->assertOk()->assertJsonPath('replay', true)->assertJsonPath('order.id', $first->json('order.id'));
        $this->assertSame(1, KitchenTicket::count());

        // второй официант пытается открыть заказ на занятом столике
        $this->openOrder($this->t1, [$this->line($this->tea)])
            ->assertStatus(409)->assertJsonPath('order_id', $first->json('order.id'));
        $this->assertSame(1, Order::count());
    }

    public function test_cannot_pay_while_kitchen_is_cooking_and_cash_must_cover_total(): void
    {
        $this->actAs($this->waiter);
        $order = $this->openOrder($this->t1, [$this->line($this->plov)])->json('order');
        $this->postJson("/api/restaurant/waiter/orders/{$order['id']}/pay", ['payment' => 'card'])
            ->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->mark($order, 'ready')->assertOk();

        $this->postJson("/api/restaurant/waiter/orders/{$order['id']}/pay", ['payment' => 'cash', 'received' => 40000])
            ->assertUnprocessable()->assertJsonValidationErrors('received');
        $this->postJson("/api/restaurant/waiter/orders/{$order['id']}/pay", ['payment' => 'card'])
            ->assertOk()->assertJsonPath('order.status', 'paid');
        // готовое при оплате считается поданным
        $this->assertSame(['served'], Order::find($order['id'])->items->pluck('status')->unique()->values()->all());
        $this->assertSame(0, Sale::sole()->change_amount);
    }

    public function test_single_items_are_marked_without_the_kitchen_screen(): void
    {
        $this->actAs($this->waiter);
        $order = $this->openOrder($this->t1, [$this->line($this->plov), $this->line($this->tea)])->json('order');
        [$plov, $tea] = $order['items'];

        // отдельные позиции: чай готов раньше плова, ошибочную отметку можно вернуть
        $this->mark($order, 'cooking', ['item_ids' => [$plov['id']]])->assertOk()->assertJsonPath('order.status', 'cooking');
        $this->mark($order, 'ready', ['item_ids' => [$tea['id']]])->assertOk()->assertJsonPath('order.status', 'cooking');
        $this->mark($order, 'ready', ['item_ids' => [$plov['id']]])->assertOk()->assertJsonPath('order.status', 'ready');
        $this->mark($order, 'cooking', ['item_ids' => [$plov['id']]])->assertOk()->assertJsonPath('order.status', 'cooking');
        $this->mark($order, 'ready', ['item_ids' => [$plov['id']]])->assertOk();
        $this->actAs($this->cook);
        $ticket = $this->board()['ready'][0];

        // «Подано» на отправку — карточка уходит с доски кухни
        $this->actAs($this->waiter);
        $this->mark($order, 'served', ['ticket_id' => $ticket['id']])->assertOk()->assertJsonPath('order.status', 'served');
        $this->actAs($this->cook);
        $this->assertSame(['new' => [], 'cooking' => [], 'ready' => []], $this->board());
        // поданное назад не возвращается; повар отметок не ставит — у кухни нет таких запросов
        $this->actAs($this->waiter);
        $this->mark($order, 'cooking', ['item_ids' => [$plov['id']]])->assertUnprocessable();
        $this->actAs($this->cook);
        $this->postJson("/api/restaurant/kitchen/items/{$plov['id']}/status", ['status' => 'cooking'])->assertNotFound();
        $this->postJson("/api/restaurant/kitchen/tickets/{$ticket['id']}/ready")->assertNotFound();
        $this->mark($order, 'ready')->assertForbidden();
    }

    public function test_cancel_rules_for_waiter_and_admin(): void
    {
        $this->printerMode('browser');
        $this->actAs($this->waiter);
        $order = $this->openOrder($this->t1, [$this->line($this->plov, 2), $this->line($this->tea)])->json('order');
        [$plov, $tea] = $order['items'];

        // ещё не начатую позицию официант отменяет сам — кухня получает чек «ОТМЕНА»
        $this->postJson("/api/restaurant/waiter/orders/{$order['id']}/items/{$tea['id']}/cancel")->assertOk()->assertJsonPath('order.total', 90000);
        $this->assertSame(1, PrintJob::where('kind', 'cancel')->count());

        // начатую — только администратор
        $this->mark($order, 'cooking')->assertOk();
        $this->postJson("/api/restaurant/waiter/orders/{$order['id']}/items/{$plov['id']}/cancel")->assertUnprocessable();
        $this->postJson("/api/restaurant/waiter/orders/{$order['id']}/cancel")->assertUnprocessable();

        $this->actAs($this->admin);
        $this->postJson("/api/restaurant/waiter/orders/{$order['id']}/cancel", ['reason' => 'Гости ушли'])
            ->assertOk()->assertJsonPath('order.status', 'cancelled');
        $this->assertSame('free', $this->tableState($this->t1)['status']);
        $this->assertSame(0, Sale::count());
        $this->getJson('/api/restaurant/admin/reports')->assertJsonPath('summary.cancelled', 1)->assertJsonPath('summary.orders', 0);
        $this->actAs($this->cook);
        $this->assertSame(['new' => [], 'cooking' => [], 'ready' => []], $this->board());
    }
}

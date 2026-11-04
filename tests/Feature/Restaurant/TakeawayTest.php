<?php

namespace Tests\Feature\Restaurant;

use App\Models\Kassa\Sale;
use App\Models\Restaurant\KitchenTicket;
use App\Models\Restaurant\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Заказы навынос у кассира: без столика → кухня → готово → «Выдан». Оплатить можно заранее (заказ закрывается
 * при выдаче) или при выдаче (заказ закрывается оплатой). Оплаченный заказ не меняется.
 */
class TakeawayTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurant();
        $this->printerMode('network');
    }

    private function takeaway(array $items, array $extra = []): array
    {
        $this->actAs($this->cashier);

        return $this->postJson('/api/restaurant/cashier/takeaway', ['items' => $items] + $extra)->assertCreated()->json('order');
    }

    public function test_prepaid_takeaway_goes_to_the_kitchen_and_closes_when_handed_out(): void
    {
        $order = $this->takeaway([$this->line($this->plov, 2, 'соус отдельно'), $this->line($this->tea)], ['comment' => 'Анвар, к 13:30']);
        $this->assertSame('takeaway', $order['type']);
        $this->assertSame('Навынос', $order['place']);
        $this->assertNull($order['table_id']);
        $this->assertSame('Малика Турсунова', $order['waiter']['name']);
        $this->assertSame(98000, $order['total']);

        // кухонный чек «НАВЫНОС», карточка на доске кухни — с пометкой навынос
        $this->assertStringContainsString('НАВЫНОС', $this->printer->texts()[0]);
        $this->assertStringContainsString('Кассир', $this->printer->texts()[0]);
        $this->actAs($this->cook);
        $card = $this->board()['new'][0];
        $this->assertTrue($card['takeaway']);
        $this->assertSame('Навынос', $card['place']);

        // столиков не занимает; в очереди кассира — с составом
        $this->actAs($this->cashier);
        $state = $this->getJson('/api/restaurant/cashier/state')->assertOk();
        $this->assertSame([], collect($state->json('tables'))->whereNotNull('order')->all());
        $this->assertSame([], $state->json('orphans'));
        $this->assertSame([['name' => 'Плов чайханский', 'qty' => 2, 'status' => 'new'], ['name' => 'Чай зелёный', 'qty' => 1, 'status' => 'new']], $state->json('takeaway.0.lines'));

        // оплата заранее, пока готовится: продажа в кассе сразу, заказ остаётся в работе
        $this->postJson("/api/restaurant/cashier/orders/{$order['id']}/pay", ['payment' => 'cash', 'received' => 100000])
            ->assertOk()->assertJsonPath('order.paid', true)->assertJsonPath('order.status', 'new')->assertJsonPath('order.change', 2000);
        $sale = Sale::with('items')->sole();
        $this->assertSame(98000, $sale->total);
        $this->assertSame('Навынос · заказ №1', $sale->customer);
        $this->assertSame($this->cashier->id, $sale->employee_id);
        $this->actAs($this->cook);
        $this->assertCount(1, $this->board()['new']); // кухня по-прежнему видит заказ
        $this->actAs($this->cashier);

        // оплаченный заказ не меняется, повторная оплата не создаёт второй продажи
        $this->postJson("/api/restaurant/cashier/orders/{$order['id']}/items", ['items' => [$this->line($this->lagman)]])
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->postJson("/api/restaurant/cashier/orders/{$order['id']}/cancel")->assertUnprocessable()->assertJsonValidationErrors('order');
        $this->postJson("/api/restaurant/cashier/orders/{$order['id']}/items/{$order['items'][1]['id']}/cancel")->assertUnprocessable();
        $this->postJson("/api/restaurant/cashier/orders/{$order['id']}/pay", ['payment' => 'card'])->assertOk()->assertJsonPath('replay', true);
        $this->assertSame(1, Sale::count());

        // выдать можно, когда всё готово
        $this->postJson("/api/restaurant/cashier/orders/{$order['id']}/handout")->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->mark($order, 'cooking', [], 'cashier')->assertOk()->assertJsonPath('order.status', 'cooking');
        $this->mark($order, 'ready', [], 'cashier')->assertOk()->assertJsonPath('order.status', 'ready');
        $this->postJson("/api/restaurant/cashier/orders/{$order['id']}/handout")
            ->assertOk()->assertJsonPath('order.status', 'paid');
        $closed = Order::find($order['id']);
        $this->assertSame($this->cashier->id, $closed->closed_by);
        $this->assertSame(['served'], $closed->items->pluck('status')->unique()->values()->all());
        $this->assertSame([], $this->getJson('/api/restaurant/cashier/state')->json('takeaway'));
        $this->actAs($this->cook);
        $this->assertSame(['new' => [], 'cooking' => [], 'ready' => []], $this->board());

        // в отчётах — «Навынос» и кассир
        $this->actAs($this->admin);
        $report = $this->getJson('/api/restaurant/admin/reports')->assertOk()
            ->assertJsonPath('summary.revenue', 98000)
            ->assertJsonPath('by_table.0.name', 'Навынос')
            ->assertJsonPath('by_waiter.0.name', 'Малика Турсунова');
        $this->assertSame(1, $report->json('summary.orders'));
    }

    public function test_takeaway_paid_on_pickup_is_handed_out_first_and_closed_by_payment(): void
    {
        $order = $this->takeaway([$this->line($this->lagman)]);
        $this->mark($order, 'ready', [], 'cashier')->assertOk();

        $this->postJson("/api/restaurant/cashier/orders/{$order['id']}/handout")
            ->assertOk()->assertJsonPath('order.status', 'served')->assertJsonPath('order.paid', false);
        $this->assertSame('served', $this->getJson('/api/restaurant/cashier/state')->json('takeaway.0.status'));
        $this->assertSame(0, Sale::count());

        // гостевой счёт навынос — без столика, с кассиром
        $receipt = collect($this->postJson("/api/restaurant/cashier/orders/{$order['id']}/bill", ['print' => false])->assertOk()->json('receipt.lines'));
        $this->assertTrue($receipt->contains(fn ($l) => ($l['left'] ?? null) === 'Навынос' && $l['right'] === 'Кассир: Малика'));

        // терминал выключен — карта отмечается вручную, как у официанта; оплата закрывает заказ
        $this->postJson("/api/restaurant/cashier/orders/{$order['id']}/pay", ['payment' => 'card'])
            ->assertOk()->assertJsonPath('order.status', 'paid')->assertJsonPath('order.payment', 'card');
        $this->assertSame(40000, Sale::sole()->total);
    }

    public function test_takeaway_is_idempotent_and_can_be_cancelled_before_payment(): void
    {
        $uuid = (string) Str::uuid();
        $first = $this->takeaway([$this->line($this->tea, 3)], ['client_uuid' => $uuid]);
        $this->postJson('/api/restaurant/cashier/takeaway', ['items' => [$this->line($this->tea, 3)], 'client_uuid' => $uuid])
            ->assertOk()->assertJsonPath('replay', true)->assertJsonPath('order.id', $first['id']);
        $this->assertSame(1, KitchenTicket::count());

        // дозаказ к неоплаченному — отдельным чеком на кухню
        $this->postJson("/api/restaurant/cashier/orders/{$first['id']}/items", ['items' => [$this->line($this->plov)]])
            ->assertCreated()->assertJsonPath('order.total', 3 * 8000 + 45000);
        $this->assertStringContainsString('ДОЗАКАЗ К №1', $this->printer->texts()[1]);

        $this->postJson("/api/restaurant/cashier/orders/{$first['id']}/cancel", ['reason' => 'гость передумал'])
            ->assertOk()->assertJsonPath('order.status', 'cancelled');
        $this->assertStringContainsString('НАВЫНОС', $this->printer->texts()[2]); // чек «ОТМЕНА» на кухню
        $this->postJson("/api/restaurant/cashier/orders/{$first['id']}/handout")->assertStatus(409);
        $this->assertSame(0, Sale::count());
    }

    public function test_handout_is_only_for_takeaway_and_admin_can_hand_out(): void
    {
        $this->actAs($this->waiter);
        $table = $this->openOrder($this->t1, [$this->line($this->tea)])->json('order');
        $this->mark($table, 'ready')->assertOk();

        $order = $this->takeaway([$this->line($this->tea)]);
        $this->postJson("/api/restaurant/cashier/orders/{$table['id']}/handout")->assertUnprocessable()->assertJsonValidationErrors('order');

        // администратор: отметки и выдача — в карточке заказа админки
        $this->actAs($this->admin);
        $this->postJson("/api/restaurant/admin/orders/{$order['id']}/status", ['status' => 'ready'])->assertOk()->assertJsonPath('order.status', 'ready');
        $this->postJson("/api/restaurant/admin/orders/{$order['id']}/handout")->assertOk()->assertJsonPath('order.status', 'served');
        $this->getJson('/api/restaurant/admin/dashboard')->assertOk()->assertJsonPath('takeaway.open', 1)->assertJsonPath('tables.busy', 1);
        $this->getJson('/api/restaurant/admin/orders?filter=open')->assertOk()->assertJsonPath('orders.0.place', 'Навынос');
    }
}

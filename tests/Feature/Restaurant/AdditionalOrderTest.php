<?php

namespace Tests\Feature\Restaurant;

use App\Models\Restaurant\KitchenTicket;
use App\Models\Restaurant\PrintJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Дозаказ: новые позиции — отдельная отправка, отдельная карточка кухни и отдельный кухонный чек. */
class AdditionalOrderTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurant();
        $this->printerMode('network');
    }

    public function test_additional_order_goes_to_kitchen_as_a_separate_ticket_with_only_new_items(): void
    {
        $this->actAs($this->waiter);
        $order = $this->openOrder($this->t1, [$this->line($this->plov, 2)], ['guests' => 2])->assertCreated()->json('order');

        $this->actAs($this->cook);
        $this->postJson("/api/restaurant/kitchen/tickets/{$order['tickets'][0]['id']}/ready")->assertOk();

        // гостям выдали счёт, но они заказали ещё
        $this->actAs($this->waiter);
        $this->postJson("/api/restaurant/waiter/orders/{$order['id']}/bill", ['print' => false])->assertOk();
        $uuid = (string) Str::uuid();
        $more = $this->postJson("/api/restaurant/waiter/orders/{$order['id']}/items", [
            'items' => [$this->line($this->lagman, 1, 'острое'), $this->line($this->tea, 2)],
            'comment' => 'подать вместе',
            'client_uuid' => $uuid,
        ])->assertCreated();
        $more->assertJsonPath('order.total', 2 * 45000 + 40000 + 2 * 8000)
            ->assertJsonPath('order.bill_at', null)
            ->assertJsonPath('order.status', 'cooking');
        $this->assertCount(2, $more->json('order.tickets'));
        $this->assertSame('busy', $this->tableState($this->t1)['status']);

        // повтор той же отправки не создаёт третий тикет
        $this->postJson("/api/restaurant/waiter/orders/{$order['id']}/items", ['items' => [$this->line($this->lagman)], 'client_uuid' => $uuid])
            ->assertOk()->assertJsonPath('replay', true);
        $this->assertSame(2, KitchenTicket::count());

        // кухня: первая отправка — в «Готово», дозаказ — отдельной карточкой в «Новых», только новые позиции
        $this->actAs($this->cook);
        $board = $this->board();
        $this->assertCount(1, $board['ready']);
        $this->assertCount(1, $board['new']);
        $this->assertSame(2, $board['new'][0]['batch']);
        $this->assertSame('подать вместе', $board['new'][0]['comment']);
        $this->assertSame(['Лагман', 'Чай зелёный'], array_column($board['new'][0]['items'], 'name'));

        // кухонный чек дозаказа — только новые позиции
        $this->assertCount(2, $this->printer->sent);
        $second = $this->printer->texts()[1];
        $this->assertStringContainsString('ДОЗАКАЗ К №1', $second);
        $this->assertStringContainsString('1 x Лагман', $second);
        $this->assertStringContainsString('>> острое', $second);
        $this->assertStringContainsString('2 x Чай зелёный', $second);
        $this->assertStringNotContainsString('Плов', $second);
        $this->assertSame(2, PrintJob::where('kind', 'kitchen')->where('status', 'done')->count());
    }

    public function test_additional_order_to_a_closed_order_is_rejected(): void
    {
        $this->actAs($this->waiter);
        $order = $this->openOrder($this->t1, [$this->line($this->tea)])->json('order');
        $this->actAs($this->admin);
        $this->postJson("/api/restaurant/waiter/orders/{$order['id']}/cancel")->assertOk();

        $this->actAs($this->waiter);
        $this->postJson("/api/restaurant/waiter/orders/{$order['id']}/items", ['items' => [$this->line($this->plov)]])
            ->assertStatus(409)->assertJsonPath('order_id', $order['id']);
    }
}

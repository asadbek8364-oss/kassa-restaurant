<?php

namespace Tests\Feature\Restaurant;

use App\Models\Kassa\Sale;
use App\Models\Restaurant\Order;
use App\Models\Restaurant\Settings;
use App\Models\Restaurant\TerminalPayment;
use App\Services\Restaurant\Terminal\FakeTerminalTransport;
use App\Services\Restaurant\Terminal\TerminalTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Оплата картой через терминал кассы: сумма уходит на терминал, заказ закрывается только после подтверждения.
 * Тестовый режим — эмулятор (успех и отказ имитирует кассир); сетевой драйвер — с фейковым транспортом.
 */
class TerminalPaymentTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantHelpers;

    private FakeTerminalTransport $terminal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurant();
        $this->terminal = new FakeTerminalTransport;
        $this->app->instance(TerminalTransport::class, $this->terminal);
    }

    /** Заказ столика, всё готово — можно платить. */
    private function readyOrder(): array
    {
        $this->actAs($this->waiter);
        $order = $this->openOrder($this->t1, [$this->line($this->plov, 2), $this->line($this->tea)])->assertCreated()->json('order');
        $this->mark($order, 'ready')->assertOk();
        $this->actAs($this->cashier);

        return $order;
    }

    private function terminalSettings(array $values): void
    {
        $this->actAs($this->admin);
        $this->putJson('/api/restaurant/admin/settings/terminal', $values + ['terminal_host' => null, 'terminal_port' => null])->assertOk();
    }

    private function start(array $order): array
    {
        return $this->postJson("/api/restaurant/cashier/orders/{$order['id']}/terminal")->assertCreated()->json('payment');
    }

    public function test_emulator_success_closes_the_order_only_after_confirmation(): void
    {
        $this->terminalSettings(['terminal_enabled' => true, 'terminal_test' => true]);
        $order = $this->readyOrder();
        $this->getJson('/api/restaurant/cashier/state')->assertJsonPath('terminal', ['enabled' => true, 'test' => true]);

        // карта у кассира — только через терминал
        $this->postJson("/api/restaurant/cashier/orders/{$order['id']}/pay", ['payment' => 'card'])->assertUnprocessable()->assertJsonValidationErrors('payment');

        $payment = $this->start($order);
        $this->assertSame('pending', $payment['status']);
        $this->assertSame(98000, $payment['amount']);
        $this->assertTrue($payment['test']);
        // пока ждём терминал — заказ открыт, продажи нет; повторное нажатие не создаёт вторую оплату
        $this->getJson("/api/restaurant/cashier/terminal/{$payment['id']}")->assertOk()->assertJsonPath('payment.status', 'pending')->assertJsonPath('order.status', 'ready');
        $this->postJson("/api/restaurant/cashier/orders/{$order['id']}/terminal")->assertJsonPath('payment.id', $payment['id']);
        $this->assertSame(0, Sale::count());

        // пока идёт оплата, заказ не меняется и не оплачивается иначе
        $this->postJson("/api/restaurant/cashier/orders/{$order['id']}/pay", ['payment' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('terminal');
        $this->postJson("/api/restaurant/cashier/orders/{$order['id']}/items", ['items' => [$this->line($this->tea)]])->assertUnprocessable()->assertJsonValidationErrors('terminal');
        $this->actAs($this->admin);
        $this->postJson("/api/restaurant/waiter/orders/{$order['id']}/cancel")->assertUnprocessable()->assertJsonValidationErrors('terminal');
        $this->putJson('/api/restaurant/admin/settings/terminal', ['terminal_enabled' => false, 'terminal_test' => true])->assertUnprocessable();
        $this->actAs($this->cashier);

        // эмулятор: «гость оплатил»
        $res = $this->postJson("/api/restaurant/cashier/terminal/{$payment['id']}/emulate", ['result' => 'approved'])->assertOk();
        $res->assertJsonPath('payment.status', 'approved')->assertJsonPath('payment.card', '•••• 4242')
            ->assertJsonPath('order.status', 'paid')->assertJsonPath('order.payment', 'card');
        $this->assertNotNull($res->json('payment.reference'));
        $sale = Sale::sole();
        $this->assertSame(['card', 98000], [$sale->payment, $sale->total]);
        $this->assertSame('free', collect($this->getJson('/api/restaurant/cashier/state')->json('tables'))->firstWhere('id', $this->t1->id)['status']);

        // повторный опрос ничего не меняет; оплата видна в карточке заказа админки
        $this->getJson("/api/restaurant/cashier/terminal/{$payment['id']}")->assertJsonPath('payment.status', 'approved');
        $this->assertSame(1, Sale::count());
        $this->actAs($this->admin);
        $this->getJson("/api/restaurant/admin/orders/{$order['id']}")->assertJsonPath('order.terminal.0.status', 'approved')->assertJsonPath('order.terminal.0.test', true);
    }

    public function test_decline_and_cancel_keep_the_order_open(): void
    {
        $this->terminalSettings(['terminal_enabled' => true, 'terminal_test' => true]);
        $order = $this->readyOrder();

        // банк отказал — заказ открыт, денег нет
        $payment = $this->start($order);
        $this->postJson("/api/restaurant/cashier/terminal/{$payment['id']}/emulate", ['result' => 'declined'])->assertOk()
            ->assertJsonPath('payment.status', 'declined')->assertJsonPath('order.status', 'ready');
        $this->assertStringContainsString('недостаточно средств', TerminalPayment::find($payment['id'])->message);
        $this->assertSame(0, Sale::count());

        // повтор — новая оплата; кассир отменил до оплаты
        $again = $this->start($order);
        $this->assertNotSame($payment['id'], $again['id']);
        $this->postJson("/api/restaurant/cashier/terminal/{$again['id']}/cancel")->assertOk()
            ->assertJsonPath('payment.status', 'cancelled')->assertJsonPath('order.status', 'ready');
        $this->postJson("/api/restaurant/cashier/terminal/{$again['id']}/emulate", ['result' => 'approved'])->assertJsonPath('payment.status', 'cancelled');
        $this->assertSame(0, Sale::count());
        $this->assertTrue(Order::find($order['id'])->isOpen());

        // наличными — как обычно
        $this->postJson("/api/restaurant/cashier/orders/{$order['id']}/pay", ['payment' => 'cash', 'received' => 100000])
            ->assertOk()->assertJsonPath('order.status', 'paid')->assertJsonPath('order.change', 2000);
    }

    public function test_terminal_off_or_order_not_ready(): void
    {
        $this->actAs($this->waiter);
        $order = $this->openOrder($this->t1, [$this->line($this->plov)])->json('order');
        $this->actAs($this->cashier);
        $this->postJson("/api/restaurant/cashier/orders/{$order['id']}/terminal")->assertUnprocessable()->assertJsonValidationErrors('terminal');

        // у заказа столика ещё готовится — на терминал не отправить
        $this->terminalSettings(['terminal_enabled' => true, 'terminal_test' => true]);
        $this->actAs($this->cashier);
        $this->postJson("/api/restaurant/cashier/orders/{$order['id']}/terminal")->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame(0, TerminalPayment::count());

        // официант по-прежнему отмечает карту вручную (переносной терминал у столика)
        $this->actAs($this->waiter);
        $this->mark($order, 'ready')->assertOk();
        $this->postJson("/api/restaurant/waiter/orders/{$order['id']}/pay", ['payment' => 'card'])->assertOk()->assertJsonPath('order.status', 'paid');
    }

    public function test_takeaway_can_be_prepaid_through_the_terminal(): void
    {
        $this->terminalSettings(['terminal_enabled' => true, 'terminal_test' => true]);
        $this->actAs($this->cashier);
        $order = $this->postJson('/api/restaurant/cashier/takeaway', ['items' => [$this->line($this->lagman)]])->assertCreated()->json('order');

        $payment = $this->start($order);
        $this->postJson("/api/restaurant/cashier/terminal/{$payment['id']}/emulate", ['result' => 'approved'])->assertOk()
            ->assertJsonPath('order.paid', true)->assertJsonPath('order.status', 'new');
        $this->mark($order, 'ready', [], 'cashier')->assertOk();
        $this->postJson("/api/restaurant/cashier/orders/{$order['id']}/handout")->assertOk()->assertJsonPath('order.status', 'paid');
        $this->assertSame('card', Sale::sole()->payment);
    }

    public function test_network_driver_with_a_fake_transport(): void
    {
        $this->terminalSettings(['terminal_enabled' => true, 'terminal_test' => false, 'terminal_host' => '192.168.1.60', 'terminal_port' => 8888]);
        $order = $this->readyOrder();

        // терминал принял сумму, гость оплатил
        $this->terminal->reply(['status' => 'pending'], ['status' => 'approved', 'rrn' => '431209876543', 'auth_code' => '123456', 'card' => '•••• 1234', 'message' => 'Одобрено']);
        $payment = $this->start($order);
        $this->assertSame('pending', $payment['status']);
        $this->assertFalse($payment['test']);
        $this->getJson("/api/restaurant/cashier/terminal/{$payment['id']}")->assertOk()
            ->assertJsonPath('payment.status', 'approved')->assertJsonPath('payment.reference', '431209876543')
            ->assertJsonPath('order.status', 'paid');
        $this->assertSame(['purchase', 'status'], $this->terminal->ops());
        $this->assertSame(['192.168.1.60', 8888], [$this->terminal->sent[0]['host'], $this->terminal->sent[0]['port']]);
        $this->assertSame(98000, $this->terminal->sent[0]['message']['amount']);
        $this->assertSame('UZS', $this->terminal->sent[0]['message']['currency']);
        // имитация — только в тестовом режиме
        $this->postJson("/api/restaurant/cashier/terminal/{$payment['id']}/emulate", ['result' => 'declined'])->assertUnprocessable();
    }

    public function test_network_driver_decline_and_unreachable_terminal(): void
    {
        $this->terminalSettings(['terminal_enabled' => true, 'terminal_test' => false, 'terminal_host' => '192.168.1.60', 'terminal_port' => 8888]);
        $order = $this->readyOrder();

        $this->terminal->reply(['status' => 'pending'], ['status' => 'declined', 'message' => 'Отказ эмитента']);
        $payment = $this->start($order);
        $this->getJson("/api/restaurant/cashier/terminal/{$payment['id']}")->assertJsonPath('payment.status', 'declined')
            ->assertJsonPath('payment.message', 'Отказ эмитента')->assertJsonPath('order.status', 'ready');

        // терминал выключен: сумма не дошла — ошибка сразу, заказ открыт
        $this->terminal->failWith = 'Терминал 192.168.1.60:8888 недоступен: Connection refused';
        $failed = $this->start($order);
        $this->assertSame('error', $failed['status']);
        $this->assertStringContainsString('недоступен', $failed['message']);

        // связь пропала во время ожидания — оплата ждёт дальше, а после срока ожидания отменяется
        $this->terminal->failWith = null;
        $this->terminal->replies = [['status' => 'pending']];
        $waiting = $this->start($order);
        $this->terminal->failWith = 'Терминал 192.168.1.60:8888 не ответил';
        $this->getJson("/api/restaurant/cashier/terminal/{$waiting['id']}")->assertJsonPath('payment.status', 'pending');
        $this->travel(config('restaurant.terminal.wait_seconds') + 5)->seconds();
        $this->getJson("/api/restaurant/cashier/terminal/{$waiting['id']}")->assertJsonPath('payment.status', 'error');
        $this->assertSame(0, Sale::count());
        $this->assertTrue(Order::find($order['id'])->isOpen());
    }

    public function test_admin_terminal_settings_and_connection_check(): void
    {
        $this->actAs($this->admin);
        $this->getJson('/api/restaurant/admin/settings')->assertJsonPath('settings.terminal_enabled', false)->assertJsonPath('terminal_driver', 'json');
        $this->postJson('/api/restaurant/admin/settings/terminal/test')->assertUnprocessable();
        // без тестового режима нужны адрес и порт
        $this->putJson('/api/restaurant/admin/settings/terminal', ['terminal_enabled' => true, 'terminal_test' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('terminal_host');
        $this->putJson('/api/restaurant/admin/settings/terminal', ['terminal_enabled' => true, 'terminal_test' => false, 'terminal_host' => 'bad host!', 'terminal_port' => 8888])
            ->assertUnprocessable()->assertJsonValidationErrors('terminal_host');

        $this->putJson('/api/restaurant/admin/settings/terminal', ['terminal_enabled' => true, 'terminal_test' => true])->assertOk()
            ->assertJsonPath('settings.terminal_test', true);
        $this->postJson('/api/restaurant/admin/settings/terminal/test')->assertOk()->assertJsonPath('ok', true);

        $this->putJson('/api/restaurant/admin/settings/terminal', ['terminal_enabled' => true, 'terminal_test' => false, 'terminal_host' => '192.168.1.60', 'terminal_port' => 8888])->assertOk();
        $this->terminal->reply(['status' => 'ok', 'model' => 'Шлюз терминала']);
        $this->postJson('/api/restaurant/admin/settings/terminal/test')->assertOk()->assertJsonPath('message', 'Связь с терминалом 192.168.1.60:8888 есть · Шлюз терминала');
        $this->terminal->failWith = 'Терминал 192.168.1.60:8888 недоступен';
        $this->postJson('/api/restaurant/admin/settings/terminal/test')->assertUnprocessable()->assertJsonPath('ok', false);
        $this->assertSame(['ping', 'ping'], $this->terminal->ops());

        // настройки терминала — этого сервера, на онлайн-сервер не уходят
        $this->assertNotContains('terminal_enabled', Settings::current()->syncFields());
    }
}

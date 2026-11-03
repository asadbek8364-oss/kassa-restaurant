<?php

namespace Tests\Feature\Restaurant;

use App\Models\Restaurant\PrintJob;
use App\Services\Restaurant\Printing\EscPosRenderer;
use App\Services\Restaurant\Printing\PrintQueue;
use App\Services\Restaurant\Printing\Receipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Печать на кухню: шаблоны чеков, ESC/POS, очередь с повтором при недоступном принтере, печать из браузера.
 * Принтер — FakePrinterTransport; настоящий принтер нужен только для финальной проверки на месте.
 */
class PrintQueueTest extends TestCase
{
    use RefreshDatabase;
    use RestaurantHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpRestaurant();
    }

    private function sendOrder(): array
    {
        $this->actAs($this->waiter);

        return $this->openOrder($this->t1, [$this->line($this->plov, 2, 'без лука'), $this->line($this->tea)], ['comment' => 'аллергия на орехи'])
            ->assertCreated()->json('order');
    }

    public function test_kitchen_ticket_is_printed_on_send_without_prices(): void
    {
        $this->printerMode('network');
        $this->sendOrder();

        $this->assertCount(1, $this->printer->sent);
        $sent = $this->printer->sent[0];
        $this->assertSame('192.168.1.50', $sent['host']);
        $this->assertSame(9100, $sent['port']);
        $this->assertStringStartsWith("\x1B@\x1Bt\x11", $sent['bytes']);      // сброс + кодовая страница CP866
        $this->assertStringEndsWith("\x1DV\x42\x00", $sent['bytes']);          // отрезка
        $this->assertStringContainsString("\x1D!\x11", $sent['bytes']);        // крупный шрифт заголовка
        $this->assertStringContainsString("\x1DB\x01", $sent['bytes']);        // инверсия для комментариев

        $text = $this->printer->texts()[0];
        foreach (['КУХНЯ', 'ЗАКАЗ №1', 'СТОЛ 1', 'Азиз Рахимов', '2 x Плов чайханский', '>> без лука', '1 x Чай зелёный', 'К ЗАКАЗУ:', 'аллергия на орехи'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
        // чек для кухни — без цен и итога
        $this->assertStringNotContainsString('45 000', $text);
        $this->assertStringNotContainsString('ИТОГО', $text);

        $job = PrintJob::sole();
        $this->assertSame(['kitchen', 'done', 'network', 1], [$job->kind, $job->status, $job->printed_via, $job->attempts]);
    }

    public function test_unavailable_printer_keeps_jobs_in_order_and_retries_with_backoff(): void
    {
        $this->printerMode('network');
        $this->printer->failWith = 'Принтер 192.168.1.50:9100 недоступен: Connection refused';
        $this->sendOrder();
        $this->openOrder($this->t2, [$this->line($this->lagman)])->assertCreated();

        // официант не ждёт принтер: заказы приняты, чеки ждут в очереди
        $jobs = PrintJob::orderBy('id')->get();
        $this->assertSame(['pending', 'pending'], $jobs->pluck('status')->all());
        $this->assertSame(1, $jobs[0]->attempts);
        $this->assertStringContainsString('недоступен', $jobs[0]->last_error);
        $this->assertEqualsWithDelta(now()->addSeconds(5)->timestamp, $jobs[0]->next_attempt_at->timestamp, 1);
        // второй чек не обгоняет первый: ждёт его, не тратя попыток
        $this->assertSame(0, $jobs[1]->attempts);
        $this->assertFalse(app(PrintQueue::class)->hasDue());

        $queue = app(PrintQueue::class);
        $this->assertSame(0, $queue->processDue()['printed']); // ещё рано
        $this->printer->failWith = null; // принтер включили
        $this->travel(6)->seconds();
        $this->assertSame(2, $queue->processDue()['printed']);
        $this->assertStringContainsString('ЗАКАЗ №1', $this->printer->texts()[0]);
        $this->assertStringContainsString('ЗАКАЗ №2', $this->printer->texts()[1]);
        $this->assertSame(['done', 'done'], PrintJob::orderBy('id')->pluck('status')->all());
    }

    public function test_job_fails_after_max_attempts_and_can_be_retried_from_admin(): void
    {
        config(['restaurant.printer.max_attempts' => 3]);
        $this->printerMode('network');
        $this->printer->failWith = 'Принтер недоступен';
        $this->sendOrder();
        $queue = app(PrintQueue::class);
        for ($i = 0; $i < 3; $i++) {
            $this->travel(61)->seconds();
            $queue->processDue();
        }
        $job = PrintJob::sole();
        $this->assertSame('failed', $job->status);
        $this->assertSame(3, $job->attempts);
        $this->actAs($this->cook);
        $this->getJson('/api/restaurant/kitchen/board')->assertJsonPath('printer_problem.failed', 1);

        $this->printer->failWith = null;
        $this->actAs($this->admin);
        $this->postJson("/api/restaurant/admin/print-jobs/{$job->id}/retry")->assertOk()->assertJsonPath('job.status', 'pending');
        // повтор уходит на принтер после ответа админке
        $this->assertSame('done', $job->fresh()->status);
        $this->assertCount(1, $this->printer->sent);
        $this->artisan('restaurant:print-queue')->assertSuccessful();
    }

    public function test_guest_receipt_has_prices_and_total_and_cp1251_works(): void
    {
        $this->printerMode('network', ['printer_codepage' => 'cp1251']);
        $order = $this->sendOrder();
        $this->actAs($this->cook);
        $this->postJson("/api/restaurant/kitchen/tickets/{$order['tickets'][0]['id']}/ready")->assertOk();
        $this->actAs($this->waiter);
        $this->postJson("/api/restaurant/waiter/orders/{$order['id']}/bill")->assertOk()->assertJsonPath('printed', true);

        $this->assertCount(2, $this->printer->sent);
        $this->assertStringStartsWith("\x1B@\x1Bt\x2E", $this->printer->sent[1]['bytes']); // ESC t 46 — WPC1251
        $text = $this->printer->texts('cp1251')[1];
        foreach (['Тестовый ресторан', 'Счёт № 1', 'Плов чайханский', '2 x 45 000', '90 000', 'ИТОГО', '98 000'] as $needle) {
            $this->assertStringContainsString($needle, $text);
        }
        $this->assertSame('guest', PrintJob::latest('id')->first()->kind);
    }

    public function test_browser_printing_hands_jobs_to_one_kitchen_screen(): void
    {
        $this->printerMode('browser');
        $this->sendOrder();
        $this->assertSame([], $this->printer->sent);

        $this->actAs($this->cook);
        $jobs = $this->getJson('/api/restaurant/kitchen/board')->assertOk()->json('print_jobs');
        $this->assertCount(1, $jobs);
        $this->assertSame('КУХНЯ', $jobs[0]['document']['lines'][0]['text']);

        $this->postJson("/api/restaurant/kitchen/print-jobs/{$jobs[0]['id']}/claim")->assertOk()->assertJsonPath('job.document.width', 48);
        $this->postJson("/api/restaurant/kitchen/print-jobs/{$jobs[0]['id']}/claim")->assertStatus(409);
        $this->assertSame([], $this->getJson('/api/restaurant/kitchen/board')->json('print_jobs'));
        $this->assertSame('browser', PrintJob::sole()->printed_via);
    }

    public function test_reprint_and_test_print(): void
    {
        $this->printerMode('network');
        $order = $this->sendOrder();
        $this->actAs($this->cook);
        $this->postJson("/api/restaurant/kitchen/tickets/{$order['tickets'][0]['id']}/reprint")->assertOk();
        $this->assertCount(2, $this->printer->sent);
        $this->assertStringContainsString('ПОВТОРНАЯ ПЕЧАТЬ', $this->printer->texts()[1]);

        $this->actAs($this->admin);
        $this->postJson('/api/restaurant/admin/settings/printer/test')->assertOk()->assertJsonPath('ok', true);
        $this->assertStringContainsString('ТЕСТОВАЯ ПЕЧАТЬ', $this->printer->texts()[2]);
        $this->printer->failWith = 'Принтер 192.168.1.50:9100 недоступен';
        $this->postJson('/api/restaurant/admin/settings/printer/test')->assertUnprocessable()->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'Принтер 192.168.1.50:9100 недоступен');

        // печать выключена — повторная печать с кухни объясняет, что делать
        $this->printerMode('off');
        $this->actAs($this->cook);
        $this->postJson("/api/restaurant/kitchen/tickets/{$order['tickets'][0]['id']}/reprint")->assertUnprocessable();
    }

    public function test_printer_settings_are_validated(): void
    {
        $this->actAs($this->admin);
        $base = ['printer_codepage' => 'cp866', 'printer_width' => 48];
        $this->putJson('/api/restaurant/admin/settings/printer', $base + ['printer_mode' => 'network'])
            ->assertUnprocessable()->assertJsonValidationErrors('printer_host');
        $this->putJson('/api/restaurant/admin/settings/printer', $base + ['printer_mode' => 'network', 'printer_host' => '192.168.1.50; rm -rf /'])
            ->assertUnprocessable()->assertJsonValidationErrors('printer_host');
        $this->putJson('/api/restaurant/admin/settings/printer', $base + ['printer_mode' => 'network', 'printer_host' => '192.168.1.77', 'printer_port' => 9101])
            ->assertOk()->assertJsonPath('settings.printer_host', '192.168.1.77')->assertJsonPath('settings.printer_port', 9101);
    }

    public function test_escpos_renderer_wraps_lines_and_aligns_rows(): void
    {
        $renderer = new EscPosRenderer;
        $this->assertSame(['Шашлык из', 'баранины на', 'мангале'], $renderer->wrap('Шашлык из баранины на мангале', 12));
        $doc = (new Receipt(32))->row('ИТОГО', '128 000')->text('Очень длинное название блюда, которое не помещается', 'left', 'l')->toArray();
        $text = mb_convert_encoding($renderer->render($doc), 'UTF-8', 'CP866');
        $this->assertStringContainsString('ИТОГО'.str_repeat(' ', 32 - 5 - 7).'128 000', $text);
        $this->assertStringContainsString("Очень длинное\n", $text); // крупный шрифт — 16 символов в строке
    }
}

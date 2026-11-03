<?php

namespace App\Services\Restaurant\Printing;

use App\Models\Kassa\Store;
use App\Models\Restaurant\KitchenTicket;
use App\Models\Restaurant\Order;
use App\Models\Restaurant\PrintJob;
use App\Models\Restaurant\Settings;
use App\Support\Kassa\StoreContext;

/**
 * Очередь печати ресторана. Принтер один — на кухне.
 *
 * Сетевой принтер (printer_mode = network): задание сохраняется в базе и печатается после ответа официанту,
 * чтобы он не ждал принтер. Если принтер недоступен, задание ждёт следующей попытки: 5, 10, 20, 40 с,
 * дальше раз в минуту (попытки запускают экран кухни при опросе и планировщик restaurant:print-queue).
 * Порядок чеков сохраняется: пока первое задание ждёт принтер, следующие ждут вместе с ним.
 * После max_attempts неудач задание помечается «не напечатано» (очередь идёт дальше) — его можно
 * повторить из админки или перепечатать с экрана кухни.
 *
 * Печать из браузера (printer_mode = browser): задания забирает экран кухни и печатает через window.print().
 */
class PrintQueue
{
    /** Рестораны, в которых после ответа клиенту нужно отправить чеки на сетевой принтер. */
    private array $deliverFor = [];

    private bool $hooked = false;

    public function __construct(
        private readonly PrinterTransport $transport,
        private readonly EscPosRenderer $renderer,
        private readonly ReceiptTemplates $templates,
    ) {}

    /** Поставить чек в очередь, если печать включена. */
    public function enqueue(string $kind, string $title, array $document, ?Order $order = null, ?KitchenTicket $ticket = null): ?PrintJob
    {
        $settings = Settings::current();
        if (! $settings->printingEnabled()) {
            return null;
        }
        $job = PrintJob::create([
            'kind' => $kind,
            'title' => mb_substr($title, 0, 80),
            'document' => $document,
            'order_id' => $order?->id,
            'ticket_id' => $ticket?->id,
        ]);
        if ($settings->printer_mode === 'network') {
            $this->deliverAfterResponse();
        }

        return $job;
    }

    /** Кухонный чек отправки (заказ или дозаказ) — только её позиции, без отменённых. */
    public function kitchenTicket(Order $order, KitchenTicket $ticket, bool $reprint = false): ?PrintJob
    {
        $ticket->loadMissing(['items', 'employee']);
        $items = $ticket->items->where('status', '!=', 'cancelled')->sortBy('id')->values();
        if ($items->isEmpty()) {
            return null;
        }
        $title = ($ticket->batch > 1 ? 'Дозаказ к №' : 'Заказ №').$order->number.' · стол '.$order->table_name.($reprint ? ' (повторно)' : '');

        return $this->enqueue('kitchen', $title, $this->templates->kitchen($order, $ticket, $items, Settings::current(), $reprint), $order, $ticket);
    }

    /**
     * После ответа клиенту — попробовать напечатать (сетевой принтер). На PHP-FPM ответ уже ушёл
     * (fastcgi_finish_request), поэтому официант не ждёт принтер, даже если тот выключен.
     */
    public function deliverAfterResponse(): void
    {
        $store = Store::current();
        $this->deliverFor[$store->id] = $store;
        if ($this->hooked) {
            return;
        }
        $this->hooked = true;
        app()->terminating(function () {
            $stores = $this->deliverFor;
            $this->deliverFor = [];
            foreach ($stores as $store) {
                app(StoreContext::class)->run($store, fn () => $this->processDue());
            }
        });
    }

    /**
     * Отправляет на сетевой принтер задания, которым пора печататься, по порядку.
     *
     * @return array{printed:int, failed:int, error:?string}
     */
    public function processDue(int $limit = 20): array
    {
        $result = ['printed' => 0, 'failed' => 0, 'error' => null];
        $settings = Settings::current();
        if ($settings->printer_mode !== 'network') {
            return $result;
        }
        // задание, взятое процессом, который не завершился, возвращается в очередь
        PrintJob::where('status', 'printing')->where('locked_until', '<', now())->update(['status' => 'pending', 'locked_until' => null]);

        // строго по порядку: пока первый чек ждёт принтер, следующие ждут вместе с ним
        $jobs = PrintJob::where('status', 'pending')->orderBy('id')->limit($limit)->get();
        foreach ($jobs as $job) {
            if ($job->next_attempt_at && $job->next_attempt_at->isFuture()) {
                break;
            }
            if (! $this->claim($job)) {
                continue;
            }
            try {
                $this->send($job, $settings);
                $result['printed']++;
            } catch (PrinterException $e) {
                $this->failed($job, $e->getMessage());
                $result['failed']++;
                $result['error'] = $e->getMessage();
                break; // принтер недоступен — остальные задания подождут
            }
        }

        return $result;
    }

    /** Пора ли отправлять на сетевой принтер (дёшево проверяется при опросе экрана кухни). */
    public function hasDue(): bool
    {
        $first = PrintJob::where('status', 'pending')->orderBy('id')->first(['id', 'next_attempt_at']);

        return $first !== null && ($first->next_attempt_at === null || ! $first->next_attempt_at->isFuture());
    }

    /** Напечатать задание сейчас, синхронно (тестовая печать из админки). */
    public function printNow(PrintJob $job): void
    {
        $settings = Settings::current();
        if (! $this->claim($job)) {
            throw new PrinterException('Задание уже печатается');
        }
        try {
            $this->send($job, $settings);
        } catch (PrinterException $e) {
            $this->failed($job, $e->getMessage());
            throw $e;
        }
    }

    /** Повторить задание, которое не напечаталось. */
    public function retry(PrintJob $job): void
    {
        $job->update(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => null, 'locked_until' => null, 'last_error' => null]);
        if (Settings::current()->printer_mode === 'network') {
            $this->deliverAfterResponse();
        }
    }

    /**
     * Печать из браузера: экран кухни забирает задание. Атомарно, чтобы два экрана не напечатали один чек.
     * Браузер не сообщает, вышла ли бумага, поэтому задание сразу считается напечатанным;
     * если чек не вышел, повар перепечатает его кнопкой на карточке заказа.
     */
    public function claimForBrowser(PrintJob $job): bool
    {
        return PrintJob::whereKey($job->id)->where('status', 'pending')->update([
            'status' => 'done', 'printed_at' => now(), 'printed_via' => 'browser',
            'attempts' => $job->attempts + 1, 'locked_until' => null,
        ]) === 1;
    }

    private function claim(PrintJob $job): bool
    {
        $claimed = ['status' => 'printing', 'locked_until' => now()->addMinute()];
        if (PrintJob::whereKey($job->id)->where('status', 'pending')->update($claimed) !== 1) {
            return false;
        }
        // модель в памяти — как в базе, иначе возврат в pending после неудачи не считался бы изменением
        $job->forceFill($claimed)->syncOriginal();

        return true;
    }

    private function send(PrintJob $job, Settings $settings): void
    {
        if (! $settings->printer_host) {
            throw new PrinterException('Не указан IP-адрес принтера — задайте его в админке: Настройки → Принтер');
        }
        $bytes = $this->renderer->render($job->document, $settings->printer_codepage);
        $this->transport->send($settings->printer_host, $settings->printer_port, $bytes, (float) config('restaurant.printer.timeout', 3));
        $job->forceFill([
            'status' => 'done', 'attempts' => $job->attempts + 1, 'printed_at' => now(), 'printed_via' => 'network',
            'locked_until' => null, 'last_error' => null, 'next_attempt_at' => null,
        ])->save();
    }

    private function failed(PrintJob $job, string $error): void
    {
        $attempts = $job->attempts + 1;
        $giveUp = $attempts >= max(1, (int) config('restaurant.printer.max_attempts', 10));
        $next = $giveUp ? null : now()->addSeconds(self::backoff($attempts));
        $job->forceFill([
            'status' => $giveUp ? 'failed' : 'pending', 'attempts' => $attempts, 'last_error' => mb_substr($error, 0, 300),
            'next_attempt_at' => $next, 'locked_until' => null,
        ])->save();
    }

    /** Пауза перед попыткой №(n+1): 5, 10, 20, 40, затем 60 секунд. */
    public static function backoff(int $attempts): int
    {
        return (int) min(60, 5 * 2 ** max(0, $attempts - 1));
    }
}

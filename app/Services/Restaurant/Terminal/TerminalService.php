<?php

namespace App\Services\Restaurant\Terminal;

use App\Models\Kassa\Employee;
use App\Models\Kassa\Store;
use App\Models\Restaurant\Order;
use App\Models\Restaurant\Settings;
use App\Models\Restaurant\TerminalPayment;
use App\Services\Restaurant\OrderService;
use App\Services\Restaurant\RestaurantConflict;
use App\Support\Restaurant\Mode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Оплата картой через терминал кассы: «Отправить на терминал» → ожидание → оплачено / отклонено / отмена.
 *
 * Сумма уходит на терминал, экран кассира опрашивает результат (refresh). Заказ закрывается (оплачивается картой
 * через OrderService::pay) только когда терминал подтвердил оплату — approved. Отказ, отмена и ошибка оставляют
 * заказ открытым. Пока оплата ждёт терминал, заказ нельзя изменить, отменить или оплатить иначе.
 *
 * Запросы к терминалу идут вне транзакций базы: официанты и кухня не ждут, пока гость вводит PIN.
 * Тестовый режим — EmulatorTerminal, иначе драйвер из config/restaurant.php (terminal.driver).
 */
class TerminalService
{
    public function __construct(private readonly OrderService $orders) {}

    /** Драйвер по настройкам: тестовый режим — эмулятор, иначе настроенный драйвер терминала. */
    public function driver(Settings $settings): PaymentTerminal
    {
        if ($settings->terminal_test) {
            return app(EmulatorTerminal::class);
        }

        return $this->byName((string) config('restaurant.terminal.driver', JsonTcpTerminal::NAME));
    }

    /** Проверка связи с терминалом (админка). */
    public function check(Settings $settings): string
    {
        return $this->driver($settings)->check($settings);
    }

    /**
     * Отправить сумму заказа на терминал. Если по заказу уже ждёт оплата — возвращается она (двойное нажатие).
     */
    public function start(Employee $by, Order $order): TerminalPayment
    {
        $this->ensureLocal();
        $settings = Settings::current();
        if (! $settings->terminalOn()) {
            throw ValidationException::withMessages(['terminal' => 'Терминал выключен — включите его в админке: Настройки → Платёжный терминал']);
        }
        $driver = $this->driver($settings);

        [$payment, $fresh] = DB::transaction(function () use ($by, $order, $driver) {
            Store::locked();
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $pending = TerminalPayment::where('order_id', $order->id)->where('status', 'pending')->first();
            if ($pending) {
                return [$pending, false];
            }
            $amount = $this->orders->payableTotal($order);

            return [TerminalPayment::create([
                'order_id' => $order->id,
                'employee_id' => $by->id,
                'amount' => $amount,
                'driver' => $driver->name(),
            ]), true];
        });

        if ($fresh) {
            $this->call($payment, fn () => $driver->purchase($payment, $settings), first: true);
        }

        return $payment->fresh();
    }

    /** Спросить терминал, чем закончилась оплата. После срока ожидания оплата отменяется. */
    public function refresh(TerminalPayment $payment): TerminalPayment
    {
        if (! $payment->isPending()) {
            return $payment;
        }
        $settings = Settings::current();
        $driver = $this->byName($payment->driver);
        $this->call($payment, fn () => $driver->status($payment, $settings));
        $payment->refresh();

        $wait = max(30, (int) config('restaurant.terminal.wait_seconds', 180));
        if ($payment->isPending() && $payment->created_at->lt(now()->subSeconds($wait))) {
            $this->call($payment, fn () => $driver->cancel($payment, $settings));
            $payment->refresh();
            if ($payment->isPending()) {
                $this->finish($payment, 'error', 'Терминал не ответил за '.intdiv($wait, 60).' мин. Проверьте экран и чек терминала: если оплата прошла, обратитесь к администратору');
            }
        }

        return $payment->fresh();
    }

    /** Кассир отменяет оплату, пока гость не оплатил. Если гость успел оплатить — заказ закрывается. */
    public function cancel(TerminalPayment $payment): TerminalPayment
    {
        if (! $payment->isPending()) {
            return $payment;
        }
        $settings = Settings::current();
        $driver = $this->byName($payment->driver);
        try {
            $this->apply($payment, $driver->cancel($payment, $settings));
        } catch (TerminalException $e) {
            $this->finish($payment, 'error', 'Отмена не подтверждена: '.$e->getMessage().'. Проверьте экран терминала');
        }

        return $payment->fresh();
    }

    /** Тестовый режим: кассир имитирует исход на эмуляторе — «гость оплатил» (approved) или «банк отказал» (declined). */
    public function emulate(TerminalPayment $payment, string $outcome): TerminalPayment
    {
        if ($payment->driver !== EmulatorTerminal::NAME) {
            throw ValidationException::withMessages(['terminal' => 'Имитация доступна только в тестовом режиме терминала']);
        }
        if (! in_array($outcome, EmulatorTerminal::OUTCOMES, true)) {
            throw ValidationException::withMessages(['result' => 'Исход: approved или declined']);
        }
        if ($payment->isPending()) {
            $payment->update(['meta' => ['outcome' => $outcome] + ($payment->meta ?? [])]);
        }

        return $this->refresh($payment);
    }

    /**
     * Вызов драйвера: ответ терминала применяется к оплате. Если сумма не дошла до терминала ($first),
     * гость оплатить не мог — оплата сразу заканчивается ошибкой. Если связь пропала во время ожидания,
     * оплата ждёт дальше (срок ожидания ограничен, см. refresh).
     */
    private function call(TerminalPayment $payment, callable $request, bool $first = false): void
    {
        try {
            $this->apply($payment, $request());
        } catch (TerminalException $e) {
            if ($first) {
                $this->finish($payment, 'error', $e->getMessage());

                return;
            }
            $payment->forceFill(['errors' => $payment->errors + 1, 'message' => mb_substr($e->getMessage(), 0, 200)])->save();
        }
    }

    private function apply(TerminalPayment $payment, TerminalResult $result): void
    {
        if ($result->status === 'pending') {
            $payment->forceFill(['meta' => $result->meta ?: $payment->meta, 'message' => $result->message, 'errors' => 0])->save();

            return;
        }
        if ($result->status !== 'approved') {
            $this->finish($payment, $result->status, $result->message ?? TerminalPayment::STATUS_NAMES[$result->status], $result);

            return;
        }

        // Оплачено: заказ оплачивается картой этой операцией. Повторный ответ «оплачено» ничего не меняет.
        try {
            DB::transaction(function () use ($payment, $result) {
                $locked = TerminalPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                if (! $locked->isPending()) {
                    return;
                }
                $by = $locked->employee ?? $locked->order->waiter;
                $this->orders->pay($by, $locked->order, 'card', null, $locked);
                $this->finish($locked, 'approved', $result->message ?? 'Оплачено', $result);
            });
        } catch (Throwable $e) {
            report($e);
            $reason = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
            $this->finish($payment->fresh(), 'error', 'Терминал подтвердил оплату, но заказ не закрыт: '.$reason.'. Обратитесь к администратору', $result);
        }
        $payment->refresh();
    }

    private function finish(TerminalPayment $payment, string $status, ?string $message, ?TerminalResult $result = null): void
    {
        $payment->forceFill([
            'status' => $status,
            'message' => $message !== null ? mb_substr($message, 0, 200) : null,
            'reference' => $result?->reference ?? $payment->reference,
            'auth_code' => $result?->authCode ?? $payment->auth_code,
            'card' => $result?->card ?? $payment->card,
            'meta' => $result && $result->meta ? $result->meta : $payment->meta,
            'finished_at' => now(),
        ])->save();
    }

    private function byName(string $name): PaymentTerminal
    {
        if ($name === EmulatorTerminal::NAME) {
            return app(EmulatorTerminal::class);
        }
        $class = config('restaurant.terminal.drivers.'.$name);
        if (! is_string($class) || ! class_exists($class)) {
            throw new RestaurantConflict('Драйвер терминала «'.$name.'» не найден — проверьте RESTAURANT_TERMINAL_DRIVER в .env');
        }

        return app($class);
    }

    private function ensureLocal(): void
    {
        if (Mode::online()) {
            throw new RestaurantConflict('Это онлайн-сервер: оплату принимает локальный сервер ресторана');
        }
    }
}

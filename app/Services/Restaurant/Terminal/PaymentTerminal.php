<?php

namespace App\Services\Restaurant\Terminal;

use App\Models\Restaurant\Settings;
use App\Models\Restaurant\TerminalPayment;

/**
 * Интерфейс слоя платёжного терминала — драйвер банковского терминала кассы.
 *
 * Оплата идёт в три шага, и ни один не держит запрос кассира, пока гость прикладывает карту:
 *  1. purchase — отправить сумму на терминал (обычно ответ «ждём карту» — pending);
 *  2. status — экран кассира раз в пару секунд спрашивает, чем закончилось: approved / declined / cancelled;
 *  3. cancel — кассир передумал, пока гость не оплатил.
 *
 * Драйверы: EmulatorTerminal — тестовый режим (имитирует оплату и отказ, денег не списывает);
 * JsonTcpTerminal — протокол-образец этого слоя (JSON по TCP). Протокол настоящего терминала зависит от его модели
 * и банка, поэтому для него пишется отдельный драйвер с этим же интерфейсом (config/restaurant.php, terminal.drivers) —
 * остальная программа, экран кассира и API при этом не меняются.
 *
 * Ошибка связи — TerminalException (терминал недоступен, не ответил, непонятный ответ).
 */
interface PaymentTerminal
{
    /** Короткое имя драйвера: сохраняется в оплате, чтобы её завершал тот же драйвер. */
    public function name(): string;

    /** Проверка связи из админки: что за терминал ответил. @throws TerminalException */
    public function check(Settings $settings): string;

    /** Отправить сумму оплаты на терминал. @throws TerminalException */
    public function purchase(TerminalPayment $payment, Settings $settings): TerminalResult;

    /** Текущее состояние оплаты на терминале. @throws TerminalException */
    public function status(TerminalPayment $payment, Settings $settings): TerminalResult;

    /** Отменить оплату, которую гость ещё не провёл. Если уже оплачено — вернуть approved. @throws TerminalException */
    public function cancel(TerminalPayment $payment, Settings $settings): TerminalResult;
}

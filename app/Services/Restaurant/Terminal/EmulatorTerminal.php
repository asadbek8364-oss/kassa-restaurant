<?php

namespace App\Services\Restaurant\Terminal;

use App\Models\Restaurant\Settings;
use App\Models\Restaurant\TerminalPayment;

/**
 * Эмулятор терминала — тестовый режим (разработка, демо, тесты). В сеть не ходит и денег не списывает.
 * После «Отправить на терминал» оплата ждёт, а кассир на экране оплаты имитирует исход: «Гость оплатил» или
 * «Банк отказал» (исход хранится в meta оплаты). Отмена до исхода — «Отменено».
 */
class EmulatorTerminal implements PaymentTerminal
{
    public const NAME = 'emulator';

    public const OUTCOMES = ['approved', 'declined'];

    public function name(): string
    {
        return self::NAME;
    }

    public function check(Settings $settings): string
    {
        return 'Тестовый режим: работает эмулятор терминала — оплата и отказ имитируются, деньги не списываются';
    }

    public function purchase(TerminalPayment $payment, Settings $settings): TerminalResult
    {
        return TerminalResult::pending('Эмулятор: ждём «оплату» гостя', ['outcome' => null]);
    }

    public function status(TerminalPayment $payment, Settings $settings): TerminalResult
    {
        return match ($payment->meta['outcome'] ?? null) {
            'approved' => new TerminalResult('approved', 'Оплачено (эмулятор терминала)',
                reference: str_pad((string) (100000000000 + $payment->id), 12, '0', STR_PAD_LEFT),
                authCode: str_pad((string) (($payment->id * 7919) % 1000000), 6, '0', STR_PAD_LEFT),
                card: '•••• 4242', meta: $payment->meta ?? []),
            'declined' => new TerminalResult('declined', 'Отказ банка: недостаточно средств (эмулятор терминала)', meta: $payment->meta ?? []),
            default => TerminalResult::pending(meta: $payment->meta ?? []),
        };
    }

    public function cancel(TerminalPayment $payment, Settings $settings): TerminalResult
    {
        $now = $this->status($payment, $settings);

        return $now->final() ? $now : new TerminalResult('cancelled', 'Оплата отменена на кассе', meta: $payment->meta ?? []);
    }
}

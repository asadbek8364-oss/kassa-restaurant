<?php

namespace App\Services\Restaurant\Terminal;

use App\Models\Restaurant\Settings;
use App\Models\Restaurant\TerminalPayment;

/**
 * Драйвер-образец: протокол этого слоя — JSON по TCP (одна строка запроса, одна строка ответа).
 *
 *   → {"op":"purchase","id":"7-15","amount":128000,"currency":"UZS","order":"Заказ №12 · стол 5"}
 *   ← {"status":"pending"}
 *   → {"op":"status","id":"7-15"}
 *   ← {"status":"approved","rrn":"431209876543","auth_code":"123456","card":"•••• 1234","message":"Одобрено"}
 *   → {"op":"cancel","id":"7-15"}            ← {"status":"cancelled"}
 *   → {"op":"ping"}                          ← {"status":"ok","model":"…"}
 *
 * Настоящие банковские терминалы этот протокол не понимают: у каждой модели и банка свой (ECR-протокол, SDK банка).
 * Его понимает шлюз — небольшая программа рядом с терминалом, которая переводит эти запросы в протокол банка,
 * а для прямого подключения пишется свой драйвер с интерфейсом PaymentTerminal (см. docs/restaurant.md).
 */
class JsonTcpTerminal implements PaymentTerminal
{
    public const NAME = 'json';

    public function __construct(private readonly TerminalTransport $transport) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function check(Settings $settings): string
    {
        $reply = $this->send($settings, ['op' => 'ping']);
        if (($reply['status'] ?? null) !== 'ok') {
            throw new TerminalException('Терминал ответил, но не подтвердил связь: '.($reply['message'] ?? json_encode($reply, JSON_UNESCAPED_UNICODE)));
        }

        return 'Связь с терминалом '.$settings->terminal_host.':'.$settings->terminal_port.' есть'.(isset($reply['model']) ? ' · '.$reply['model'] : '');
    }

    public function purchase(TerminalPayment $payment, Settings $settings): TerminalResult
    {
        $payment->loadMissing('order');

        return $this->result($this->send($settings, [
            'op' => 'purchase',
            'id' => $this->id($payment),
            'amount' => (int) $payment->amount,
            'currency' => 'UZS',
            'order' => 'Заказ №'.$payment->order?->number.' · '.mb_strtolower((string) $payment->order?->place()),
        ]));
    }

    public function status(TerminalPayment $payment, Settings $settings): TerminalResult
    {
        return $this->result($this->send($settings, ['op' => 'status', 'id' => $this->id($payment)]));
    }

    public function cancel(TerminalPayment $payment, Settings $settings): TerminalResult
    {
        return $this->result($this->send($settings, ['op' => 'cancel', 'id' => $this->id($payment)]));
    }

    private function send(Settings $settings, array $message): array
    {
        if (! $settings->terminal_host || ! $settings->terminal_port) {
            throw new TerminalException('Не указан адрес или порт терминала — задайте их в админке: Настройки → Платёжный терминал');
        }

        return $this->transport->exchange($settings->terminal_host, (int) $settings->terminal_port, $message, (float) config('restaurant.terminal.timeout', 5));
    }

    private function result(array $reply): TerminalResult
    {
        $status = $reply['status'] ?? null;
        if (! in_array($status, ['pending', 'approved', 'declined', 'cancelled'], true)) {
            throw new TerminalException('Непонятный ответ терминала'.(isset($reply['message']) ? ': '.$reply['message'] : ''));
        }
        $text = fn (string $key, int $max) => isset($reply[$key]) && is_scalar($reply[$key]) ? mb_substr((string) $reply[$key], 0, $max) : null;

        return new TerminalResult($status, $text('message', 200), $text('rrn', 40), $text('auth_code', 20), $text('card', 30));
    }

    /** Номер операции для терминала: ресторан и номер оплаты — уникален на этом сервере. */
    private function id(TerminalPayment $payment): string
    {
        return $payment->store_id.'-'.$payment->id;
    }
}

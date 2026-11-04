<?php

namespace App\Services\Restaurant\Terminal;

/**
 * Связь с терминалом в локальной сети: одно сообщение — один ответ. Настоящая — TcpTerminalTransport,
 * в тестах — FakeTerminalTransport. Драйвер конкретного терминала может использовать свой транспорт
 * (кадры с длиной, STX/ETX и т. п.) — интерфейс слоя PaymentTerminal от этого не меняется.
 */
interface TerminalTransport
{
    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     *
     * @throws TerminalException терминал недоступен, не ответил или ответ не разобрать
     */
    public function exchange(string $host, int $port, array $message, float $timeout): array;
}

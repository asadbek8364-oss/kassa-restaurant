<?php

namespace App\Services\Restaurant\Terminal;

/**
 * Терминал для тестов без железа: отвечает заготовленными ответами по порядку, запоминает запросы,
 * может притворяться недоступным.
 */
class FakeTerminalTransport implements TerminalTransport
{
    /** @var list<array{host:string, port:int, message:array}> */
    public array $sent = [];

    /** @var list<array> ответы по порядку; когда закончились — повторяется последний */
    public array $replies = [];

    /** Если задано — каждый запрос падает с этим сообщением (терминал выключен). */
    public ?string $failWith = null;

    public function reply(array ...$replies): static
    {
        $this->replies = array_merge($this->replies, $replies);

        return $this;
    }

    public function exchange(string $host, int $port, array $message, float $timeout): array
    {
        $this->sent[] = ['host' => $host, 'port' => $port, 'message' => $message];
        if ($this->failWith !== null) {
            throw new TerminalException($this->failWith);
        }
        if ($this->replies === []) {
            return ['status' => 'pending'];
        }

        return count($this->replies) > 1 ? array_shift($this->replies) : $this->replies[0];
    }

    /** Операции запросов по порядку: purchase, status, cancel… */
    public function ops(): array
    {
        return array_map(fn ($s) => $s['message']['op'] ?? null, $this->sent);
    }
}

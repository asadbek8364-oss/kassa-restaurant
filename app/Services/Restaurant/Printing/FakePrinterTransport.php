<?php

namespace App\Services\Restaurant\Printing;

/**
 * Принтер для тестов и разработки без железа: запоминает, что «напечатано», может притворяться недоступным.
 */
class FakePrinterTransport implements PrinterTransport
{
    /** @var list<array{host:string, port:int, bytes:string}> */
    public array $sent = [];

    /** Если задано — каждая отправка падает с этим сообщением (принтер выключен). */
    public ?string $failWith = null;

    public function send(string $host, int $port, string $bytes, float $timeout): void
    {
        if ($this->failWith !== null) {
            throw new PrinterException($this->failWith);
        }
        $this->sent[] = ['host' => $host, 'port' => $port, 'bytes' => $bytes];
    }

    /** Напечатанный текст (кириллица из cp866 обратно в UTF-8) — для проверок в тестах. */
    public function texts(string $codepage = 'cp866'): array
    {
        $encoding = $codepage === 'cp1251' ? 'Windows-1251' : 'CP866';

        return array_map(fn ($s) => mb_convert_encoding($s['bytes'], 'UTF-8', $encoding), $this->sent);
    }
}

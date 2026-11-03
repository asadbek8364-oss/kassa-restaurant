<?php

namespace App\Services\Restaurant\Printing;

/**
 * Доставка байтов ESC/POS до принтера. Настоящая — TcpPrinterTransport (порт 9100),
 * в тестах — FakePrinterTransport.
 */
interface PrinterTransport
{
    /** @throws PrinterException принтер недоступен или связь оборвалась */
    public function send(string $host, int $port, string $bytes, float $timeout): void;
}

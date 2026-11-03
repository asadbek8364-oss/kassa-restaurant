<?php

namespace App\Services\Restaurant\Printing;

/**
 * Сетевой термопринтер: «сырой» поток ESC/POS на TCP-порт (обычно 9100, режим RAW / JetDirect).
 */
class TcpPrinterTransport implements PrinterTransport
{
    public function send(string $host, int $port, string $bytes, float $timeout): void
    {
        $address = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$host}]" : $host;
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client("tcp://{$address}:{$port}", $errno, $errstr, $timeout);
        if (! $socket) {
            throw new PrinterException("Принтер {$host}:{$port} недоступен".($errstr !== '' ? ": {$errstr}" : ''));
        }

        try {
            stream_set_timeout($socket, max(1, (int) ceil($timeout)));
            $written = 0;
            $length = strlen($bytes);
            while ($written < $length) {
                $n = @fwrite($socket, substr($bytes, $written));
                if ($n === false || $n === 0) {
                    throw new PrinterException("Связь с принтером {$host}:{$port} прервалась во время печати");
                }
                $written += $n;
            }
            fflush($socket);
        } finally {
            fclose($socket);
        }
    }
}

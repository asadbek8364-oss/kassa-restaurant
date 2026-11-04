<?php

namespace App\Services\Restaurant\Terminal;

/**
 * JSON по TCP: запрос — одна строка JSON с переводом строки, ответ — одна строка JSON.
 * Используется драйвером-образцом JsonTcpTerminal.
 */
class TcpTerminalTransport implements TerminalTransport
{
    public function exchange(string $host, int $port, array $message, float $timeout): array
    {
        $address = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$host}]" : $host;
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client("tcp://{$address}:{$port}", $errno, $errstr, $timeout);
        if (! $socket) {
            throw new TerminalException("Терминал {$host}:{$port} недоступен".($errstr !== '' ? ": {$errstr}" : ''));
        }

        try {
            stream_set_timeout($socket, max(1, (int) ceil($timeout)));
            $line = json_encode($message, JSON_UNESCAPED_UNICODE)."\n";
            if (@fwrite($socket, $line) !== strlen($line)) {
                throw new TerminalException("Связь с терминалом {$host}:{$port} прервалась");
            }
            $reply = fgets($socket, 65536);
            if ($reply === false) {
                $meta = stream_get_meta_data($socket);
                throw new TerminalException($meta['timed_out'] ?? false ? "Терминал {$host}:{$port} не ответил" : "Терминал {$host}:{$port} закрыл соединение");
            }
        } finally {
            fclose($socket);
        }

        $data = json_decode(trim($reply), true);
        if (! is_array($data)) {
            throw new TerminalException('Непонятный ответ терминала — проверьте драйвер терминала (RESTAURANT_TERMINAL_DRIVER)');
        }

        return $data;
    }
}

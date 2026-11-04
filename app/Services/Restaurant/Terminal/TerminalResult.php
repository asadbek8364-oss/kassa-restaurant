<?php

namespace App\Services\Restaurant\Terminal;

/**
 * Ответ терминала: состояние оплаты и реквизиты операции. meta — состояние драйвера, которое нужно сохранить
 * в оплате до следующего запроса (номер операции на терминале и т. п.).
 */
final class TerminalResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $message = null,
        public readonly ?string $reference = null,
        public readonly ?string $authCode = null,
        public readonly ?string $card = null,
        public readonly array $meta = [],
    ) {
        if (! in_array($status, ['pending', 'approved', 'declined', 'cancelled'], true)) {
            throw new TerminalException('Терминал вернул неизвестное состояние оплаты: '.$status);
        }
    }

    public static function pending(?string $message = null, array $meta = []): self
    {
        return new self('pending', $message, meta: $meta);
    }

    public function final(): bool
    {
        return $this->status !== 'pending';
    }
}

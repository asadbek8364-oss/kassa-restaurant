<?php

namespace App\Services\Restaurant\Printing;

/**
 * Чек как набор строк со стилями — общий формат для сетевого принтера (EscPosRenderer)
 * и печати из браузера (тот же документ рисует public/restaurant/common.js).
 *
 * Строки: text (текст с выравниванием и размером), row (слева и справа, например «Итого … 128 000»),
 * rule (разделитель), feed (пустые строки).
 * Размер: n — обычный, t — двойная высота, l — двойная ширина и высота (в строке вдвое меньше символов).
 */
class Receipt
{
    private array $lines = [];

    public function __construct(private readonly int $width = 48) {}

    public function text(string $text, string $align = 'left', string $size = 'n', bool $bold = false, bool $invert = false): static
    {
        $this->lines[] = ['t' => 'text', 'text' => $text, 'align' => $align, 'size' => $size, 'bold' => $bold, 'invert' => $invert];

        return $this;
    }

    public function row(string $left, string $right, string $size = 'n', bool $bold = false): static
    {
        $this->lines[] = ['t' => 'row', 'left' => $left, 'right' => $right, 'size' => $size, 'bold' => $bold];

        return $this;
    }

    public function rule(string $char = '-'): static
    {
        $this->lines[] = ['t' => 'rule', 'char' => $char];

        return $this;
    }

    public function feed(int $lines = 1): static
    {
        $this->lines[] = ['t' => 'feed', 'n' => $lines];

        return $this;
    }

    public function toArray(): array
    {
        return ['width' => $this->width, 'lines' => $this->lines];
    }
}

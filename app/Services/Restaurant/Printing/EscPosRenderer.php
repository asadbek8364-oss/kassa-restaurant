<?php

namespace App\Services\Restaurant\Printing;

use App\Models\Restaurant\Settings;

/**
 * Превращает чек (Receipt::toArray) в байты ESC/POS для термопринтера 80 мм (Epson TM и совместимые:
 * Xprinter, Rongta, HPRT и т. п.).
 *
 * Кириллица печатается через кодовую страницу принтера: cp866 (ESC t 17) или cp1251 (ESC t 46).
 * Номер таблицы у разных моделей может отличаться — правильность проверяет «Тестовая печать» в админке.
 */
class EscPosRenderer
{
    private const ESC = "\x1B";

    private const GS = "\x1D";

    /** GS ! n: обычный, двойная высота, двойная ширина и высота. */
    private const SIZES = ['n' => 0x00, 't' => 0x01, 'l' => 0x11];

    private const ALIGN = ['left' => 0, 'center' => 1, 'right' => 2];

    /** Символы, которых нет в кодовых страницах принтера. */
    private const REPLACE = [
        '«' => '"', '»' => '"', '„' => '"', '“' => '"', '”' => '"', '‘' => "'", '’' => "'", 'ʻ' => "'", 'ʼ' => "'",
        '—' => '-', '–' => '-', '−' => '-', '×' => 'x', '…' => '...', "\u{00A0}" => ' ', "\u{202F}" => ' ', '•' => '*',
    ];

    public function render(array $document, string $codepage = 'cp866'): string
    {
        $width = max(24, (int) ($document['width'] ?? 48));
        $table = Settings::CODEPAGES[$codepage] ?? Settings::CODEPAGES['cp866'];

        $out = self::ESC.'@'                     // сброс принтера
            .self::ESC.'t'.chr($table);          // кодовая страница кириллицы

        foreach ($document['lines'] ?? [] as $line) {
            $size = $line['size'] ?? 'n';
            $chars = $size === 'l' ? intdiv($width, 2) : $width;
            $out .= match ($line['t'] ?? 'text') {
                'rule' => $this->style('left', 'n', false, false).$this->encode(str_repeat((string) ($line['char'] ?? '-'), $width), $codepage)."\n",
                'feed' => self::ESC.'d'.chr(max(1, min(10, (int) ($line['n'] ?? 1)))),
                'row' => $this->style('left', $size, (bool) ($line['bold'] ?? false), false)
                    .$this->lines($this->row((string) $line['left'], (string) $line['right'], $chars), $codepage),
                default => $this->style($line['align'] ?? 'left', $size, (bool) ($line['bold'] ?? false), (bool) ($line['invert'] ?? false))
                    .$this->lines($this->wrap((string) ($line['text'] ?? ''), $chars), $codepage),
            };
        }

        return $out
            .$this->style('left', 'n', false, false)
            .self::ESC.'d'.chr(4)                // протяжка до ножа
            .self::GS.'V'.chr(66).chr(0);        // отрезка с подачей (частичная)
    }

    private function style(string $align, string $size, bool $bold, bool $invert): string
    {
        return self::ESC.'a'.chr(self::ALIGN[$align] ?? 0)
            .self::ESC.'E'.chr($bold ? 1 : 0)
            .self::GS.'!'.chr(self::SIZES[$size] ?? 0)
            .self::GS.'B'.chr($invert ? 1 : 0);
    }

    private function lines(array $lines, string $codepage): string
    {
        $out = '';
        foreach ($lines as $l) {
            $out .= $this->encode($l, $codepage)."\n";
        }

        return $out;
    }

    /** Слева текст, справа сумма; длинное название переносится, сумма — на последней строке. */
    private function row(string $left, string $right, int $chars): array
    {
        $rightLen = mb_strlen($right);
        $lines = $this->wrap($left, $chars);
        $last = array_pop($lines);
        if (mb_strlen($last) + 1 + $rightLen <= $chars) {
            $lines[] = $last.str_repeat(' ', $chars - mb_strlen($last) - $rightLen).$right;
        } else {
            $lines[] = $last;
            $lines[] = str_repeat(' ', max(0, $chars - $rightLen)).$right;
        }

        return $lines;
    }

    /** Перенос по словам; слишком длинное слово режется. */
    public function wrap(string $text, int $chars): array
    {
        $out = [];
        foreach (preg_split('/\R/u', $text) as $paragraph) {
            $line = '';
            foreach (preg_split('/\s+/u', trim($paragraph)) as $word) {
                while (mb_strlen($word) > $chars) {
                    if ($line !== '') {
                        $out[] = $line;
                        $line = '';
                    }
                    $out[] = mb_substr($word, 0, $chars);
                    $word = mb_substr($word, $chars);
                }
                if ($line === '') {
                    $line = $word;
                } elseif (mb_strlen($line) + 1 + mb_strlen($word) <= $chars) {
                    $line .= ' '.$word;
                } else {
                    $out[] = $line;
                    $line = $word;
                }
            }
            $out[] = $line;
        }

        return $out;
    }

    public function encode(string $text, string $codepage): string
    {
        $text = strtr($text, self::REPLACE);
        $encoding = $codepage === 'cp1251' ? 'Windows-1251' : 'CP866';

        return mb_convert_encoding($text, $encoding, 'UTF-8');
    }
}

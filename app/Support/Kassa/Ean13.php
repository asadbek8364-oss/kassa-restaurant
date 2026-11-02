<?php

namespace App\Support\Kassa;

class Ean13
{
    public static function checkDigit(string $first12): int
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $first12[$i] * ($i % 2 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10;
    }

    public static function isValid(?string $code): bool
    {
        return is_string($code)
            && preg_match('/^\d{13}$/', $code) === 1
            && self::checkDigit($code) === (int) $code[12];
    }

    /** Убирает пробелы; 12-значный UPC-A дополняет ведущим нулём. */
    public static function normalize(?string $code): string
    {
        $code = preg_replace('/\s+/', '', (string) $code);

        return preg_match('/^\d{12}$/', $code) === 1 ? '0'.$code : $code;
    }

    /** Внутренний код магазина: префикс 200 (in-store) + 9 случайных цифр + контрольная. */
    public static function internal(): string
    {
        $first12 = '200'.str_pad((string) random_int(0, 999_999_999), 9, '0', STR_PAD_LEFT);

        return $first12.self::checkDigit($first12);
    }
}

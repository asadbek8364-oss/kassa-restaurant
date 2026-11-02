<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * PIN сотрудника кассы: 4–6 цифр и не из тех, что подбирают первыми
 * (одинаковые цифры, подряд по возрастанию или убыванию, самые частые PIN).
 */
class KassaPin implements ValidationRule
{
    private const COMMON = ['1212', '1122', '2580', '0852', '6969', '1004', '2000', '1010', '2001', '1313', '7777777', '121212', '112233', '123123', '654321', '696969'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $pin = (string) $value;
        if (preg_match('/^\d{4,6}$/', $pin) !== 1) {
            $fail('PIN — от 4 до 6 цифр');

            return;
        }
        if (self::isWeak($pin)) {
            $fail('PIN слишком простой: не используйте одинаковые цифры, цифры подряд (1234, 4321) и частые комбинации');
        }
    }

    public static function isWeak(string $pin): bool
    {
        if (in_array($pin, self::COMMON, true) || count(array_unique(str_split($pin))) === 1) {
            return true;
        }
        $up = $down = true;
        for ($i = 1; $i < strlen($pin); $i++) {
            $d = (int) $pin[$i] - (int) $pin[$i - 1];
            $up = $up && $d === 1;
            $down = $down && $d === -1;
        }

        return $up || $down;
    }
}

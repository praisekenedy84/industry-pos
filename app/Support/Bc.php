<?php

namespace App\Support;

final class Bc
{
    public static function num(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.0000';
        }

        if (is_float($value)) {
            return number_format($value, 4, '.', '');
        }

        return bcadd((string) $value, '0', 4);
    }

    public static function add(mixed $a, mixed $b): string
    {
        return bcadd(self::num($a), self::num($b), 4);
    }

    public static function sub(mixed $a, mixed $b): string
    {
        return bcsub(self::num($a), self::num($b), 4);
    }

    public static function mul(mixed $a, mixed $b): string
    {
        return bcmul(self::num($a), self::num($b), 4);
    }

    public static function div(mixed $a, mixed $b): string
    {
        if (bccomp(self::num($b), '0', 4) === 0) {
            return '0.0000';
        }

        return bcdiv(self::num($a), self::num($b), 4);
    }

    public static function cmp(mixed $a, mixed $b): int
    {
        return bccomp(self::num($a), self::num($b), 4);
    }

    public static function minZero(mixed $value): string
    {
        $n = self::num($value);

        return bccomp($n, '0', 4) < 0 ? '0.0000' : $n;
    }
}

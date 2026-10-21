<?php

namespace App\Support;

use InvalidArgumentException;

class Money
{
    public static function cents(string|int|float|null $value): int
    {
        $text = trim((string) $value);

        if ($text === '') {
            return 0;
        }

        if (! preg_match('/^-?\d+(\.\d{1,2})?$/', $text)) {
            throw new InvalidArgumentException('Enter an amount with up to two decimal places.');
        }

        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '-');
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '0');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
        $cents = ((int) $whole * 100) + (int) $fraction;

        return $negative ? -$cents : $cents;
    }

    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function inr(string $amount): string
    {
        $formatted = self::format(self::cents($amount));
        $negative = str_starts_with($formatted, '-');
        $formatted = ltrim($formatted, '-');
        [$whole, $fraction] = explode('.', $formatted);

        if (strlen($whole) > 3) {
            $tail = substr($whole, -3);
            $head = substr($whole, 0, -3);
            $head = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $head) ?? $head;
            $whole = $head.','.$tail;
        }

        return ($negative ? '-' : '').'₹'.$whole.'.'.$fraction;
    }

    public static function add(string $left, string $right): string
    {
        return self::format(self::cents($left) + self::cents($right));
    }

    public static function subtract(string $left, string $right): string
    {
        return self::format(self::cents($left) - self::cents($right));
    }
}

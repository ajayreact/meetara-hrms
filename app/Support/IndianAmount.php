<?php

namespace App\Support;

use InvalidArgumentException;

class IndianAmount
{
    /**
     * @var array<int, string>
     */
    private const ONES = [
        0 => '',
        1 => 'One',
        2 => 'Two',
        3 => 'Three',
        4 => 'Four',
        5 => 'Five',
        6 => 'Six',
        7 => 'Seven',
        8 => 'Eight',
        9 => 'Nine',
        10 => 'Ten',
        11 => 'Eleven',
        12 => 'Twelve',
        13 => 'Thirteen',
        14 => 'Fourteen',
        15 => 'Fifteen',
        16 => 'Sixteen',
        17 => 'Seventeen',
        18 => 'Eighteen',
        19 => 'Nineteen',
    ];

    /**
     * @var array<int, string>
     */
    private const TENS = [
        2 => 'Twenty',
        3 => 'Thirty',
        4 => 'Forty',
        5 => 'Fifty',
        6 => 'Sixty',
        7 => 'Seventy',
        8 => 'Eighty',
        9 => 'Ninety',
    ];

    public static function figures(int|float|string $amount): string
    {
        $rupees = self::rupees($amount);
        $digits = (string) $rupees;

        if (strlen($digits) <= 3) {
            return $digits;
        }

        $last = substr($digits, -3);
        $rest = substr($digits, 0, -3);
        $groups = [];

        while (strlen($rest) > 2) {
            array_unshift($groups, substr($rest, -2));
            $rest = substr($rest, 0, -2);
        }

        if ($rest !== '') {
            array_unshift($groups, $rest);
        }

        return implode(',', $groups).','.$last;
    }

    public static function words(int|float|string $amount): string
    {
        $rupees = self::rupees($amount);

        if ($rupees === 0) {
            return 'Rupees Zero Only.';
        }

        $crore = intdiv($rupees, 10000000);
        $rupees %= 10000000;
        $lakh = intdiv($rupees, 100000);
        $rupees %= 100000;
        $thousand = intdiv($rupees, 1000);
        $rupees %= 1000;
        $parts = [];

        if ($crore > 0) {
            $parts[] = self::belowThousand($crore).' Crore';
        }

        if ($lakh > 0) {
            $parts[] = self::belowThousand($lakh).' Lakh';
        }

        if ($thousand > 0) {
            $parts[] = self::belowThousand($thousand).' Thousand';
        }

        if ($rupees > 0) {
            $parts[] = self::belowThousand($rupees);
        }

        return 'Rupees '.implode(' ', $parts).' Only.';
    }

    private static function rupees(int|float|string $amount): int
    {
        if (is_string($amount)) {
            $amount = str_replace([',', ' ', '₹'], '', $amount);
        }

        if (! is_numeric($amount)) {
            throw new InvalidArgumentException('Salary amount must be numeric.');
        }

        $value = (float) $amount;

        if ($value < 0 || $value > 999999999) {
            throw new InvalidArgumentException('Salary amount is outside the supported range.');
        }

        return (int) round($value);
    }

    private static function belowThousand(int $number): string
    {
        $words = '';

        if ($number >= 100) {
            $words .= self::ONES[intdiv($number, 100)].' Hundred';
            $number %= 100;

            if ($number > 0) {
                $words .= ' ';
            }
        }

        if ($number >= 20) {
            $words .= self::TENS[intdiv($number, 10)];
            $number %= 10;

            if ($number > 0) {
                $words .= '-'.self::ONES[$number];
            }
        } elseif ($number > 0) {
            $words .= self::ONES[$number];
        }

        return $words;
    }
}

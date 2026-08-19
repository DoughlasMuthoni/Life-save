<?php

namespace App\Domain\Finance\Support;

use InvalidArgumentException;

/**
 * Converts between human-entered decimal amounts and BIGINT minor units,
 * using pure string/integer arithmetic — never a float — so a form input
 * like "1500.50" can't drift due to floating-point rounding before it ever
 * reaches the ledger (CLAUDE.md §10).
 */
class Money
{
    public static function toMinorUnits(string $amount): int
    {
        $amount = trim(str_replace(',', '', $amount));

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $amount)) {
            throw new InvalidArgumentException("Invalid amount: [{$amount}]. Use a plain number like 1500 or 1500.50.");
        }

        [$whole, $decimal] = array_pad(explode('.', $amount, 2), 2, '0');
        $decimal = str_pad(substr($decimal, 0, 2), 2, '0');

        return ((int) $whole) * 100 + (int) $decimal;
    }

    public static function formatMinor(int $minorUnits, string $currency = 'KES'): string
    {
        $negative = $minorUnits < 0;
        $minorUnits = abs($minorUnits);

        $whole = intdiv($minorUnits, 100);
        $decimal = $minorUnits % 100;

        $symbol = match ($currency) {
            'KES' => 'KSh',
            default => $currency,
        };

        $formattedWhole = number_format($whole);

        return ($negative ? '-' : '').$symbol.' '.$formattedWhole.'.'.str_pad((string) $decimal, 2, '0', STR_PAD_LEFT);
    }
}

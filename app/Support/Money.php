<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class Money
{
    public static function decimal(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function cents(string $value): int
    {
        if (! preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/D', $value, $m)) {
            throw ValidationException::withMessages(['amount' => 'Invalid monetary amount.']);
        }

        return ((int) $m[1] * 100) + (int) str_pad($m[2] ?? '', 2, '0');
    }

    public static function percentage(int $cents, int $basisPoints): int
    {
        return intdiv($cents * $basisPoints + 5000, 10000);
    }
}

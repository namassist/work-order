<?php

namespace App\Support;

/**
 * Rupiah amounts as shown everywhere: "Rp 1.500.000", or "Rp 1.500.000,50"
 * when there are cents. Matches formatRupiah() in resources/js/lib/format.ts.
 * Works on the decimal string (e.g. "1500000.50"), never through a float.
 */
class Rupiah
{
    public static function format(string $amount): string
    {
        [$whole, $cents] = array_pad(explode('.', trim($amount), 2), 2, '');
        $cents = str_pad(substr($cents, 0, 2), 2, '0');

        $grouped = number_format((int) $whole, 0, ',', '.');

        return 'Rp '.$grouped.($cents === '00' ? '' : ','.$cents);
    }
}

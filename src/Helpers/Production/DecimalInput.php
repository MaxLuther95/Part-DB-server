<?php

declare(strict_types=1);

namespace App\Helpers\Production;

/** Decimal notation without binary floating point, rounding or added zeroes. */
final class DecimalInput
{
    public static function normalize(string $value): string
    {
        $value = trim($value);
        if (strlen($value) > 128 || ! preg_match('/^[+-]?(?:\d+(?:[.,]\d*)?|[.,]\d+)$/D', $value)) {
            throw new \InvalidArgumentException('Enter a decimal number without thousands separators or an exponent (maximum 128 characters).');
        }
        $value = str_replace(',', '.', $value);
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, null);
        $integer = ltrim($integer, '0');

        return ($negative ? '-' : '').('' === $integer ? '0' : $integer).(null !== $fraction && '' !== $fraction ? '.'.$fraction : '');
    }
}

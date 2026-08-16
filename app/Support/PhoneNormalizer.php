<?php

namespace App\Support;

use InvalidArgumentException;

class PhoneNormalizer
{
    public function normalize(string $value): string
    {
        $trimmed = trim($value);
        $hasInternationalPrefix = str_starts_with($trimmed, '+');
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if (! $hasInternationalPrefix) {
            if (str_starts_with($digits, '0')) {
                $digits = '62'.substr($digits, 1);
            } elseif (str_starts_with($digits, '8')) {
                $digits = '62'.$digits;
            }
        }

        if (! preg_match('/^[1-9]\d{7,14}$/', $digits)) {
            throw new InvalidArgumentException('WhatsApp number must be a valid international number.');
        }

        return '+'.$digits;
    }
}

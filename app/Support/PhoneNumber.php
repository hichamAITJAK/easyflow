<?php

namespace App\Support;

class PhoneNumber
{
    /**
     * Format a Moroccan phone number to 0XXXXXXXXX (10 digits).
     * Takes the last 9 digits and prefixes with 0.
     */
    public static function format(?string $number): ?string
    {
        if (! $number) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $number);

        if (strlen($digits) < 9) {
            return null;
        }

        return '0'.substr($digits, -9);
    }
}

<?php

namespace App\Support;

final class Phone
{
    /**
     * Keep digits and a single leading "+" (e.g. "+92 300-1234567" => "+923001234567").
     */
    public static function normalize(string $phone): string
    {
        $phone = trim($phone);
        $plus = str_starts_with($phone, '+') ? '+' : '';

        return $plus.preg_replace('/\D+/', '', $phone);
    }
}

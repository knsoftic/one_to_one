<?php

namespace App\Support;

use App\Models\AppSetting;

final class Phone
{
    /** Number of trailing digits used to index and match phone numbers. */
    public const SUFFIX_LENGTH = 9;

    /**
     * Keep digits and a single leading "+" (e.g. "+92 300-1234567" => "+923001234567").
     */
    public static function normalize(string $phone): string
    {
        $phone = trim($phone);
        $plus = str_starts_with($phone, '+') ? '+' : '';

        return $plus.preg_replace('/\D+/', '', $phone);
    }

    /**
     * A number typed for an account, in international form: "0300 1234567" becomes
     * "+923001234567" with the app's default country code (admin panel, default +92).
     * Numbers that already start with "+" or "00" keep their own country code.
     */
    public static function forAccount(string $phone, ?string $countryCode = null): string
    {
        $phone = self::normalize($phone);
        $code = self::normalize((string) ($countryCode ?? AppSetting::get('default_country_code')));

        if (str_starts_with($phone, '00')) {
            return '+'.substr($phone, 2);
        }
        if ($phone !== '' && ! str_starts_with($phone, '+') && str_starts_with($phone, '0') && preg_match('/^\+\d{1,4}$/', $code)) {
            return $code.ltrim($phone, '0');
        }

        return $phone;
    }

    /**
     * Digits only, without the international "00" / trunk "0" prefixes.
     * "+92 300 1234567", "0092 300 1234567" and "0300 1234567" => "923001234567" / "3001234567".
     */
    public static function significantDigits(string $phone): string
    {
        return ltrim((string) preg_replace('/\D+/', '', $phone), '0');
    }

    /**
     * Last digits used for indexed lookups (null when the number is too short to match safely).
     */
    public static function suffix(string $phone): ?string
    {
        $digits = self::significantDigits($phone);

        return strlen($digits) >= self::SUFFIX_LENGTH ? substr($digits, -self::SUFFIX_LENGTH) : null;
    }

    /**
     * Whether two numbers written in different formats refer to the same phone,
     * e.g. a phone-book "0300 1234567" and a stored "+923001234567".
     */
    public static function matches(string $a, string $b): bool
    {
        $a = self::significantDigits($a);
        $b = self::significantDigits($b);

        if (strlen($a) < self::SUFFIX_LENGTH || strlen($b) < self::SUFFIX_LENGTH) {
            return false;
        }

        return str_ends_with($a, $b) || str_ends_with($b, $a);
    }
}

<?php

namespace App\Support;

/**
 * A short, readable device name from a browser's user agent:
 * "Chrome on Windows", "One2One app on Android", "Safari on iPhone".
 */
class UserAgent
{
    public static function describe(?string $userAgent): string
    {
        $ua = (string) $userAgent;
        if ($ua === '') {
            return 'Unknown device';
        }

        $os = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        // The Android app shows the site in a WebView ("; wv)").
        if (str_contains($ua, 'Android') && str_contains($ua, '; wv)')) {
            return config('app.name').' app on Android';
        }

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser',
        };

        return $os ? "{$browser} on {$os}" : $browser;
    }

    public static function isMobile(?string $userAgent): bool
    {
        return (bool) preg_match('/Android|iPhone|iPad|Mobile/i', (string) $userAgent);
    }
}

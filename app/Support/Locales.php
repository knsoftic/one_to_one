<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * X1 — app languages. The page is written in English; for Urdu the browser swaps the
 * text from lang/ur.json and the layout turns right-to-left.
 */
final class Locales
{
    /** Language code => its own name. */
    public const SUPPORTED = ['en' => 'English', 'ur' => 'اردو'];

    public const RIGHT_TO_LEFT = ['ur'];

    public const COOKIE = 'locale';

    /** One year, for people who aren't signed in. */
    public const COOKIE_MINUTES = 525600;

    public static function isSupported(mixed $locale): bool
    {
        return is_string($locale) && array_key_exists($locale, self::SUPPORTED);
    }

    public static function direction(?string $locale = null): string
    {
        return in_array($locale ?? app()->getLocale(), self::RIGHT_TO_LEFT, true) ? 'rtl' : 'ltr';
    }

    /** The signed-in person's choice, else the cookie, else the app's default language. */
    public static function forRequest(Request $request): string
    {
        // app.locale changes with setLocale(), so the default comes from fallback_locale.
        $candidates = [$request->user()?->locale, $request->cookie(self::COOKIE), config('app.fallback_locale')];

        foreach ($candidates as $locale) {
            if (self::isSupported($locale)) {
                return $locale;
            }
        }

        return 'en';
    }
}

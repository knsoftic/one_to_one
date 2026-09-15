<?php

namespace App\Http\Controllers;

use App\Support\Locales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rule;

/**
 * X1 — switch the app language (signed in: saved on the account; otherwise a cookie).
 */
class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys(Locales::SUPPORTED))],
        ]);
        $locale = $validated['locale'];

        if ($user = $request->user()) {
            $user->forceFill(['locale' => $locale])->save();
        }

        Cookie::queue(Cookie::make(Locales::COOKIE, $locale, Locales::COOKIE_MINUTES, sameSite: 'lax'));

        if ($request->expectsJson()) {
            return response()->json(['locale' => $locale, 'dir' => Locales::direction($locale)]);
        }

        $status = $locale === 'ur' ? 'ایپ کی زبان اردو ہو گئی۔' : 'App language changed to English.';

        return back()->with('status', $status);
    }
}

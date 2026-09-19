<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\User;
use App\Services\Payments\PlayGateway;
use Illuminate\Http\Request;

/**
 * The switches and shared helpers of the paid features (Y2): the master switch, the currency,
 * which platform a request comes from, and what the settings page should show a person.
 */
class MonetisationService
{
    /** Currencies the admin can price in; the symbol is what people see. */
    public const CURRENCIES = ['PKR' => 'Rs', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'AED' => 'AED', 'SAR' => 'SAR', 'INR' => '₹'];

    public function enabled(): bool
    {
        return (bool) AppSetting::get('paid_enabled');
    }

    public function promoteEnabled(): bool
    {
        return $this->enabled() && (bool) AppSetting::get('promote_enabled');
    }

    public function referralEnabled(): bool
    {
        return $this->enabled() && (bool) AppSetting::get('referral_enabled');
    }

    public function currency(): string
    {
        $code = strtoupper((string) AppSetting::get('paid_currency'));

        return array_key_exists($code, self::CURRENCIES) ? $code : 'PKR';
    }

    /** "Rs 1,200" / "$4.99" — money is stored in integer minor units. */
    public function formatMoney(int $minor, ?string $currency = null): string
    {
        $currency = strtoupper($currency ?? $this->currency());
        $symbol = self::CURRENCIES[$currency] ?? $currency.' ';
        $amount = $minor / 100;
        $text = $minor % 100 === 0 ? number_format($amount) : number_format($amount, 2);

        return $symbol === 'Rs' || strlen($symbol) > 1 && ctype_alpha($symbol) ? "{$symbol} {$text}" : "{$symbol}{$text}";
    }

    /**
     * Where the request comes from: the Android shell appends "One2OneApp/" to its user agent.
     * A best-effort signal — Google's own verification is the real guard for Play purchases.
     */
    public function platform(Request $request): string
    {
        return str_contains((string) $request->userAgent(), 'One2OneApp/') ? 'android' : 'web';
    }

    /** Which paid rows the settings page shows this person. */
    public function settingsFlags(User $user): array
    {
        $on = $this->enabled();

        return [
            'premium' => $on,
            'wallet' => $on,
            'promote' => $this->promoteEnabled(),
            'refer' => $this->referralEnabled(),
        ];
    }

    /** The `paid` block of the page config (read by the settings-page JS). */
    public function configFor(?User $user, Request $request): array
    {
        if (! $this->enabled()) {
            return ['enabled' => false];
        }

        $plans = app(PlanService::class);
        $active = $user ? $plans->activeFor($user) : null;

        return [
            'enabled' => true,
            'promote' => $this->promoteEnabled(),
            'referral' => $this->referralEnabled(),
            'currency' => $this->currency(),
            'symbol' => self::CURRENCIES[$this->currency()] ?? $this->currency(),
            'platform' => $this->platform($request),
            'verified' => $user ? app(BadgeService::class)->isVerified($user) : false,
            'plan' => $active ? ['name' => $active->plan?->name, 'until' => $active->ends_at?->toIso8601String()] : null,
            'play' => [
                // The same gate as PaymentService::methodsFor(): switched on AND actually
                // configured, or the app would open a Play sheet whose purchase can never be
                // verified (and Google refunds it after three days).
                'enabled' => app(PlayGateway::class)->available(),
                'accountHash' => $user ? hash('sha256', $user->getKey().config('app.key')) : null,
                'minAppCode' => AppSetting::get('play_min_app_code') ? (int) AppSetting::get('play_min_app_code') : null,
            ],
            'withdraw' => (bool) AppSetting::get('wallet_withdraw_enabled'),
        ];
    }
}

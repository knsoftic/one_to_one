<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\CoinPack;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Payments for plans and coin packs (Y2) through a manual transfer, Stripe, PayPal or Google
 * Play. Which methods a person may use is decided here, on the server, from the platform the
 * request comes from: the Android app gets Google Play only (Play policy), the web gets the rest.
 *
 * NOTE: begin / proof / settle / fulfil / refund and the gateway drivers are implemented by the
 * payments slice; `methodsFor()` is the contract the wallet and premium screens rely on.
 */
class PaymentService
{
    public function __construct(private readonly MonetisationService $money) {}

    /**
     * The gateways offered to this person for this item, in display order.
     *
     * @return list<string>
     */
    public function methodsFor(User $user, Request $request, Plan|CoinPack|null $item = null): array
    {
        if (! $this->money->enabled()) {
            return [];
        }

        if ($this->money->platform($request) === 'android') {
            $playOk = (bool) AppSetting::get('play_enabled') && ($item === null || filled($item->play_product_id));

            return $playOk ? ['play'] : [];
        }

        $methods = [];
        if ((bool) AppSetting::get('manual_enabled') && $this->manualMethods()) {
            $methods[] = 'manual';
        }
        if ((bool) AppSetting::get('stripe_enabled') && filled(config('services.stripe.secret'))) {
            $methods[] = 'stripe';
        }
        if ((bool) AppSetting::get('paypal_enabled') && filled(config('services.paypal.client_id')) && filled(config('services.paypal.secret'))
            && ($item === null || (int) $item->price_usd_minor > 0)) {
            $methods[] = 'paypal';
        }

        return $methods;
    }

    /** The manual transfer methods the admin wrote instructions for. */
    public function manualMethods(): array
    {
        $out = [];
        foreach (['jazzcash' => 'JazzCash', 'easypaisa' => 'EasyPaisa', 'bank' => 'Bank transfer'] as $key => $label) {
            $text = trim((string) AppSetting::get('manual_'.$key));
            if ($text !== '') {
                $out[$key] = ['label' => $label, 'text' => $text];
            }
        }

        return $out;
    }
}

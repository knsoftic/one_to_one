<?php

namespace App\Services\Payments;

use App\Models\Payment;

/**
 * One way to take money (Y2): a manual transfer, Stripe, PayPal or Google Play. PaymentService
 * owns the payment's state; a driver only starts the checkout and talks to the provider.
 */
interface PaymentGateway
{
    /** `manual` | `stripe` | `paypal` | `play` */
    public function key(): string;

    /** Switched on by the admin and configured well enough to be offered. */
    public function available(): bool;

    /**
     * Start the checkout for a pending payment. Sets `gateway_ref` when the provider gave one.
     *
     * @return array{redirect?: string, instructions?: array}
     */
    public function start(Payment $payment): array;

    /**
     * Ask the provider to give the money back. Returns the provider's refund id, or throws a
     * PaymentException when the provider cannot do it from here (manual, Play).
     */
    public function refund(Payment $payment, ?string $note): ?string;
}

<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentException;
use App\Models\AppSetting;
use App\Models\Payment;
use App\Services\PaymentService;

/**
 * JazzCash / EasyPaisa / bank transfer (Y2): the person pays outside the app, uploads a
 * screenshot, and an admin approves it. No external calls.
 */
class ManualGateway implements PaymentGateway
{
    public function __construct(private readonly PaymentService $payments) {}

    public function key(): string
    {
        return 'manual';
    }

    public function available(): bool
    {
        return (bool) AppSetting::get('manual_enabled') && $this->payments->manualMethods() !== [];
    }

    public function start(Payment $payment): array
    {
        return ['instructions' => $this->payments->manualInstructions($payment)];
    }

    public function refund(Payment $payment, ?string $note): ?string
    {
        throw new PaymentException('refund_unsupported', 'Send the money back outside the app, then record the refund here.', 422);
    }
}

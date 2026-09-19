<?php

namespace App\Http\Controllers;

use App\Services\Payments\PayPalGateway;
use App\Services\Payments\StripeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Throwable;
use UnexpectedValueException;

/**
 * Payment providers calling back (Y2). No session, CSRF-exempt; each provider's own signature is
 * checked. 400 = we could not trust the message (nothing recorded), 200 = applied or a duplicate,
 * 500 = recorded but not applied, so the provider retries and the event row is re-processed.
 */
class WebhookController extends Controller
{
    public function stripe(Request $request, StripeGateway $stripe): JsonResponse
    {
        try {
            $result = $stripe->handleWebhook((string) $request->getContent(), (string) $request->header('Stripe-Signature', ''));
        } catch (SignatureVerificationException $e) {
            Log::warning('Stripe webhook with a bad signature was ignored: '.$e->getMessage());

            return response()->json(['error' => 'bad_signature'], 400);
        } catch (UnexpectedValueException $e) {
            return response()->json(['error' => 'bad_payload'], 400);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['error' => 'processing_failed'], 500);
        }

        return response()->json($result);
    }

    public function paypal(Request $request, PayPalGateway $paypal): JsonResponse
    {
        try {
            $ok = $paypal->handleWebhook($request);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['error' => 'processing_failed'], 500);
        }

        if (! $ok) {
            Log::warning('PayPal webhook could not be verified and was ignored.');

            return response()->json(['error' => 'unverified'], 400);
        }

        return response()->json(['status' => 'ok']);
    }
}

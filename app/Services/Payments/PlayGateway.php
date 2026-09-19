<?php

namespace App\Services\Payments;

use App\Exceptions\PaymentException;
use App\Models\AppSetting;
use App\Models\CoinPack;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Plan;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Google Play Billing (Y2): the Android app buys a consumable product and posts the purchase
 * token here; the server asks the Play Developer API about it before crediting anything, and the
 * app consumes the purchase only after this server said yes. Plans are consumable products too
 * ("1 month Pro"), so there is no subscription API and no RTDN — a daily sweep of voided
 * purchases covers refunds instead.
 */
class PlayGateway implements PaymentGateway
{
    public const API = 'https://androidpublisher.googleapis.com/androidpublisher/v3/applications';

    /** Where the voided-purchases sweep last looked (ISO time). */
    public const SWEEP_SINCE_KEY = 'play:voided:since';

    public function __construct(private readonly PaymentService $payments, private readonly GoogleServiceAccount $account) {}

    public function key(): string
    {
        return 'play';
    }

    public function available(): bool
    {
        return (bool) AppSetting::get('play_enabled') && $this->account->configured() && filled($this->packageName());
    }

    public function packageName(): string
    {
        return (string) config('services.play.package_name');
    }

    /** Play purchases start in the app (Billing sheet) and reach the server through verify(). */
    public function start(Payment $payment): array
    {
        throw new PaymentException('use_play', 'In the app, buy through Google Play.', 422);
    }

    public function refund(Payment $payment, ?string $note): ?string
    {
        throw new PaymentException('refund_unsupported', 'Refund this purchase in the Play Console; the daily check reverses it, or press Reverse now.', 422);
    }

    /** Stable per user, so a restored purchase can be bound back to the account that made it. */
    public function accountHash(User $user): string
    {
        return hash('sha256', $user->getKey().config('app.key'));
    }

    /**
     * The product ids the app may buy, with what each one delivers.
     *
     * @return list<array{productId: string, purpose: string, item_id: int}>
     */
    public function products(): array
    {
        $out = [];
        foreach (Plan::query()->where('is_active', true)->whereNotNull('play_product_id')->orderBy('sort')->get(['id', 'play_product_id']) as $plan) {
            $out[] = ['productId' => $plan->play_product_id, 'purpose' => 'plan', 'item_id' => $plan->id];
        }
        foreach (CoinPack::query()->where('is_active', true)->whereNotNull('play_product_id')->orderBy('sort')->get(['id', 'play_product_id']) as $pack) {
            $out[] = ['productId' => $pack->play_product_id, 'purpose' => 'coins', 'item_id' => $pack->id];
        }

        return $out;
    }

    /**
     * The app bought (or restored) a purchase: check it with Google and deliver it once.
     *
     * Throws a PaymentException the controller answers with: 409 `token_other_account`,
     * 503 `google_unavailable` (the app must NOT consume), 422 `unknown_product` / `cancelled` /
     * `consumed` / `account_mismatch` / `order_reused` / `invalid_token` / `voided`.
     *
     * @return array{status: 'fulfilled'|'pending', payment: Payment}
     */
    public function verify(User $user, string $productId, string $purchaseToken, ?string $orderId, Request $request): array
    {
        $hash = hash('sha256', $purchaseToken);

        // (1) Seen this token before?
        $existing = Payment::query()->where('gateway', 'play')->where('gateway_ref', $hash)->first();
        if ($existing) {
            if ($existing->user_id !== $user->getKey()) {
                Log::warning("Play purchase token replayed by another account: payment #{$existing->id} belongs to user #{$existing->user_id}, user #{$user->id} tried it.");
                throw new PaymentException('token_other_account', 'This purchase belongs to another account.', 409);
            }
            if ($existing->status === 'fulfilled') {
                return ['status' => 'fulfilled', 'payment' => $existing];
            }
            if ($existing->status === 'paid') {
                $this->payments->fulfil($existing);

                return ['status' => $existing->status === 'fulfilled' ? 'fulfilled' : 'pending', 'payment' => $existing];
            }
            if ($existing->status === 'refunded') {
                throw new PaymentException('voided', 'Google refunded this purchase.', 422);
            }
            if ($existing->status !== 'pending') {
                throw new PaymentException('cancelled', 'This purchase was not completed.', 422);
            }
            // pending (Google said "pending" last time): ask again below.
        }

        // (2) What is being bought.
        $item = $this->itemFor($productId);
        if (! $item) {
            throw new PaymentException('unknown_product', 'This product is not for sale.', 422);
        }

        // (3) Ask Google.
        $purchase = $this->fetchPurchase($productId, $purchaseToken);

        // (4) Checks.
        $state = (int) ($purchase['purchaseState'] ?? -1);
        if ($state === 1) {
            if ($existing) {
                $this->payments->fail($existing, 'cancelled');
            }
            throw new PaymentException('cancelled', 'Google Play says this purchase was cancelled.', 422);
        }
        if ($state === 2) {
            $payment = $existing ?? $this->createPayment($user, $item, $productId, $hash, $orderId, $purchase);

            return ['status' => 'pending', 'payment' => $payment];
        }
        if ($state !== 0) {
            throw new PaymentException('invalid_token', 'Google Play did not recognise this purchase.', 422);
        }
        if ((int) ($purchase['consumptionState'] ?? 0) === 1 && ! $existing) {
            Log::warning("Play purchase already consumed without a payment: user #{$user->id}, product {$productId}, order ".($orderId ?? '?'));
            throw new PaymentException('consumed', 'This purchase was already used. Contact support if you did not receive it.', 422);
        }
        $expected = $this->accountHash($user);
        $reported = (string) ($purchase['obfuscatedExternalAccountId'] ?? '');
        if ($reported !== '' && ! hash_equals($expected, $reported)) {
            throw new PaymentException('account_mismatch', 'This purchase was made from a different account.', 422);
        }
        $orderId = $orderId ?: (is_string($purchase['orderId'] ?? null) ? $purchase['orderId'] : null);
        if ($orderId && Payment::query()->where('gateway', 'play')->where('gateway_capture_ref', $orderId)
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->exists()) {
            throw new PaymentException('order_reused', 'This Google Play order was already used.', 422);
        }

        // (5) The payment row; the unique (gateway, gateway_ref) index settles a concurrent verify.
        $payment = $existing ?? $this->createPayment($user, $item, $productId, $hash, $orderId, $purchase);
        if ($payment->user_id !== $user->getKey()) {
            throw new PaymentException('token_other_account', 'This purchase belongs to another account.', 409);
        }

        // (6) Deliver.
        $this->payments->settle($payment, [
            'gateway_capture_ref' => $orderId,
            'meta' => ['google' => $this->trim($purchase)],
        ]);

        // (7) Acknowledge — best effort; the app's consume acknowledges too.
        if ($payment->status === 'fulfilled' && (int) ($purchase['acknowledgementState'] ?? 0) === 0) {
            $this->acknowledge($productId, $purchaseToken);
        }

        return ['status' => $payment->status === 'fulfilled' ? 'fulfilled' : 'pending', 'payment' => $payment];
    }

    /**
     * Purchases Google voided (refunded, charged back) since a time.
     *
     * @return list<array{purchaseToken: string, orderId: ?string, voidedTimeMillis: ?string, voidedReason?: int}>
     */
    public function voidedPurchases(Carbon $since): array
    {
        $out = [];
        $pageToken = null;
        do {
            $response = $this->api()->get($this->base().'/purchases/voidedpurchases', array_filter([
                'startTime' => $since->getTimestampMs(),
                'token' => $pageToken,
            ]));
            if (! $response->successful()) {
                throw new PaymentException('google_unavailable', 'Google did not list voided purchases (HTTP '.$response->status().').', 503);
            }
            foreach ((array) $response->json('voidedPurchases', []) as $row) {
                if (is_array($row) && is_string($row['purchaseToken'] ?? null)) {
                    $out[] = $row;
                }
            }
            $pageToken = $response->json('tokenPagination.nextPageToken');
        } while (is_string($pageToken) && $pageToken !== '');

        return $out;
    }

    /**
     * Scheduled (chat:play-sweep): reverse every delivered purchase Google voided since the last
     * run (minus two days of overlap). Each token is applied once through payment_events.
     */
    public function sweepVoided(): int
    {
        if (! $this->available()) {
            return 0;
        }

        $last = Cache::get(self::SWEEP_SINCE_KEY);
        $since = is_string($last) ? Carbon::parse($last)->subDays(2) : now()->subDays(30);
        $startedAt = now();
        $n = 0;

        foreach ($this->voidedPurchases($since) as $void) {
            $hash = hash('sha256', (string) $void['purchaseToken']);
            $payment = Payment::query()->where('gateway', 'play')->where('gateway_ref', $hash)->whereIn('status', ['fulfilled', 'paid'])->first();
            if (! $payment) {
                continue;
            }
            $event = $this->payments->recordEvent('play', 'void:'.$hash, 'void', $this->trimVoid($void), $payment);
            if ($this->payments->isProcessed($event)) {
                continue;
            }
            try {
                $this->payments->processEvent($event, function (PaymentEvent $row) use ($payment) {
                    $this->payments->reverse($payment, 'play_void');
                });
                $n++;
            } catch (Throwable $e) {
                report($e);
                Log::warning("Play void for payment #{$payment->id} not applied: ".$e->getMessage());
            }
        }

        Cache::forever(self::SWEEP_SINCE_KEY, $startedAt->toIso8601String());

        return $n;
    }

    /** Admin → Test connection: list the app's in-app products. */
    public function check(): array
    {
        if (! $this->account->configured()) {
            return ['ok' => false, 'message' => 'Paste the service-account JSON and save first.'];
        }
        if (! filled($this->packageName())) {
            return ['ok' => false, 'message' => 'Enter the package name and save first.'];
        }
        try {
            $this->account->accessToken(fresh: true);
            $response = $this->api()->get($this->base().'/inappproducts', ['maxResults' => 100]);
            if (! $response->successful()) {
                $detail = (string) ($response->json('error.message') ?? 'HTTP '.$response->status());

                return ['ok' => false, 'message' => 'Google refused the request: '.$detail];
            }
            $ids = collect((array) $response->json('inappproduct', []))->pluck('sku')->filter()->values();
            $known = collect($this->products())->pluck('productId');
            $missing = $known->diff($ids);
            $message = 'Google Play answers for '.$this->packageName().': '.$ids->count().' in-app product(s).';
            if ($missing->isNotEmpty()) {
                $message .= ' Not found in the Play Console: '.$missing->implode(', ').'.';
            }

            return ['ok' => true, 'message' => $message];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Google Play refused the service account: '.$e->getMessage()];
        }
    }

    /* ---- Google API calls ---------------------------------------------------------------- */

    /** The purchase as Google sees it; 5xx / timeout ⇒ 503 (the app must not consume). */
    private function fetchPurchase(string $productId, string $purchaseToken): array
    {
        try {
            $response = $this->api()->get($this->base().'/purchases/products/'.rawurlencode($productId).'/tokens/'.rawurlencode($purchaseToken));
        } catch (PaymentException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PaymentException('google_unavailable', 'Google Play did not answer. Try again in a moment.', 503);
        }

        if ($response->successful()) {
            return (array) $response->json();
        }
        if (in_array($response->status(), [400, 404, 410], true)) {
            throw new PaymentException('invalid_token', 'Google Play did not recognise this purchase.', 422);
        }

        Log::warning("Play purchase lookup failed: HTTP {$response->status()} ".Str::limit($response->body(), 300));
        throw new PaymentException('google_unavailable', 'Google Play did not answer. Try again in a moment.', 503);
    }

    private function acknowledge(string $productId, string $purchaseToken): void
    {
        try {
            $this->api()->post($this->base().'/purchases/products/'.rawurlencode($productId).'/tokens/'.rawurlencode($purchaseToken).':acknowledge', (object) []);
        } catch (Throwable $e) {
            Log::info('Play acknowledge skipped: '.$e->getMessage());
        }
    }

    private function api(): PendingRequest
    {
        return Http::withToken($this->account->accessToken())->acceptJson()->timeout(15);
    }

    private function base(): string
    {
        return self::API.'/'.rawurlencode($this->packageName());
    }

    /* ---- helpers ------------------------------------------------------------------------- */

    private function itemFor(string $productId): Plan|CoinPack|null
    {
        return Plan::query()->where('play_product_id', $productId)->where('is_active', true)->first()
            ?? CoinPack::query()->where('play_product_id', $productId)->where('is_active', true)->first();
    }

    /** Insert the payment; when a concurrent verify already did, take theirs. */
    private function createPayment(User $user, Plan|CoinPack $item, string $productId, string $hash, ?string $orderId, array $purchase): Payment
    {
        try {
            return Payment::query()->create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->getKey(),
                'purpose' => $item instanceof Plan ? 'plan' : 'coins',
                'plan_id' => $item instanceof Plan ? $item->getKey() : null,
                'coin_pack_id' => $item instanceof CoinPack ? $item->getKey() : null,
                'gateway' => 'play',
                'status' => 'pending',
                'platform' => 'android',
                'amount_minor' => (int) $item->price_minor,
                'currency' => strtoupper((string) $item->currency),
                'coins' => $item instanceof CoinPack ? $item->totalCoins() : null,
                'gateway_ref' => $hash,
                'gateway_capture_ref' => $orderId,
                'meta' => ['google' => $this->trim($purchase), 'product_id' => $productId],
            ]);
        } catch (UniqueConstraintViolationException) {
            return Payment::query()->where('gateway', 'play')->where('gateway_ref', $hash)->firstOrFail();
        }
    }

    private function trim(array $purchase): array
    {
        return array_filter(array_intersect_key($purchase, array_flip([
            'orderId', 'purchaseState', 'consumptionState', 'acknowledgementState', 'purchaseTimeMillis', 'priceAmountMicros', 'priceCurrencyCode', 'regionCode', 'quantity',
        ])), fn ($v) => $v !== null && ! is_array($v));
    }

    private function trimVoid(array $void): array
    {
        return array_filter(array_intersect_key($void, array_flip(['orderId', 'voidedTimeMillis', 'voidedSource', 'voidedReason'])), fn ($v) => $v !== null && ! is_array($v));
    }
}

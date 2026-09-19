<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\CoinPack;
use App\Models\CoinTransaction;
use App\Models\Payment;
use App\Models\User;
use App\Services\CoinService;
use App\Services\MonetisationService;
use App\Services\Payments\PlayGateway;
use App\Services\PaymentService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Settings › Wallet (Y2): the balance, coin packs and the ways to pay for them, payments still in
 * progress, and the ledger. The screen is rendered by resources/js/ui/wallet.js from this JSON.
 */
class WalletController extends Controller
{
    public function __construct(
        private readonly CoinService $coins,
        private readonly MonetisationService $money,
        private readonly PaymentService $payments,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'summary' => $this->coins->summary($user),
            'packs' => CoinPack::query()->where('is_active', true)->orderBy('sort')->orderBy('id')->get()
                ->map(fn (CoinPack $pack) => [
                    'id' => $pack->id,
                    'name' => $pack->name,
                    'coins' => $pack->coins,
                    'bonus_coins' => $pack->bonus_coins,
                    'total_coins' => $pack->totalCoins(),
                    'price_minor' => $pack->price_minor,
                    'currency' => $pack->currency,
                    'price_display' => $this->money->formatMoney($pack->price_minor, $pack->currency),
                    'price_usd_minor' => $pack->price_usd_minor,
                    'play_product_id' => $pack->play_product_id,
                ])->values(),
            'methods' => $this->payments->methodsFor($user, $request),
            'pending' => $this->pending($user, $this->money->platform($request)),
            'play' => [
                'enabled' => app(PlayGateway::class)->available(),
                'accountHash' => hash('sha256', $user->getKey().config('app.key')),
                'minAppCode' => AppSetting::get('play_min_app_code') ? (int) AppSetting::get('play_min_app_code') : null,
            ],
            'history' => $this->page($this->coins->history($user)),
            'refund_url' => route('legal', 'refunds'),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        return response()->json($this->page($this->coins->history($request->user())));
    }

    /** Withdrawals are tracked from day one but not paid out yet. */
    public function withdraw(Request $request): JsonResponse
    {
        if (! (bool) AppSetting::get('wallet_withdraw_enabled')) {
            return response()->json(['code' => 'coming_soon', 'message' => 'Withdrawals are coming soon.'], 409);
        }

        return response()->json(['code' => 'coming_soon', 'message' => 'Withdrawals are not available yet.'], 409);
    }

    /**
     * Payments the person still has to finish, wait for, or that are being delivered.
     *
     * In the Android app only Google Play payments are listed: a manual / Stripe / PayPal payment
     * started on the website would otherwise put bank instructions, a screenshot form or a
     * "Continue payment" link to a web checkout inside the app, which Play's payments policy
     * (anti-steering) forbids. They stay visible on the website, where they were started.
     */
    private function pending(User $user, string $platform): array
    {
        return Payment::query()->where('user_id', $user->getKey())->whereIn('status', ['pending', 'review', 'paid'])
            ->when($platform === 'android', fn ($q) => $q->where('gateway', 'play'))
            ->with(['plan', 'coinPack'])->latest('id')->limit(10)->get()
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'status' => $p->status,
                'gateway' => $p->gateway,
                'purpose' => $p->purpose,
                'item' => $p->itemLabel(),
                'amount_display' => $this->money->formatMoney($p->amount_minor, $p->currency),
                'created_at' => $p->created_at?->toIso8601String(),
                'manual_method' => $p->manual_method,
                'proof_ref' => $p->proof_ref,
                'review_note' => $p->review_note,
            ])->values()->all();
    }

    private function page(LengthAwarePaginator $rows): array
    {
        return [
            'data' => collect($rows->items())->map(fn (CoinTransaction $row) => [
                'id' => $row->id,
                'type' => $row->type,
                'label' => $row->label(),
                'amount' => $row->amount,
                'withdrawable_delta' => $row->withdrawable_delta,
                'balance_after' => $row->balance_after,
                'note' => $row->note,
                'created_at' => $row->created_at?->toIso8601String(),
                'reference' => $row->reference_type ? ['type' => $row->reference_type, 'id' => $row->reference_id] : null,
            ])->values()->all(),
            'page' => $rows->currentPage(),
            'has_more' => $rows->hasMorePages(),
            'next_page' => $rows->hasMorePages() ? $rows->currentPage() + 1 : null,
        ];
    }
}

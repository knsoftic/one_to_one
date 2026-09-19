<?php

namespace App\Http\Controllers\Admin;

use App\Console\Commands\ChatDoctor;
use App\Http\Controllers\Controller;
use App\Models\AdCampaign;
use App\Models\AppSetting;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\Referral;
use App\Models\Subscription;
use App\Models\Wallet;
use App\Services\MonetisationService;
use App\Services\Payments\GoogleServiceAccount;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Admin → Money → Overview (Y2): revenue, coins, subscriptions, what waits for review, and the
 * flags an operator must look at (mismatches, shortfalls, undelivered payments, stale cron).
 */
class MoneyController extends Controller
{
    public const DAYS = 30;

    public function __construct(private readonly MonetisationService $money, private readonly PaymentService $payments) {}

    public function index(Request $request): View
    {
        $since = now()->subDays(self::DAYS);
        $currency = $this->money->currency();

        // Revenue per gateway and currency: fulfilled payments only (refunded ones left the till again).
        $revenue = Payment::query()->where('status', 'fulfilled')->where('fulfilled_at', '>=', $since)
            ->selectRaw('gateway, currency, COUNT(*) n, SUM(amount_minor) total')->groupBy('gateway', 'currency')
            ->orderByDesc('total')->get()
            ->map(fn ($r) => ['gateway' => Payment::GATEWAYS[$r->gateway] ?? $r->gateway, 'currency' => $r->currency, 'count' => (int) $r->n, 'total' => $this->money->formatMoney((int) $r->total, $r->currency)])
            ->all();
        $revenueMain = (int) Payment::query()->where('status', 'fulfilled')->where('fulfilled_at', '>=', $since)->where('currency', $currency)->sum('amount_minor');

        // Daily revenue in the main currency for the sparkline.
        $byDay = Payment::query()->where('status', 'fulfilled')->where('fulfilled_at', '>=', $since->copy()->startOfDay())->where('currency', $currency)
            ->selectRaw('DATE(fulfilled_at) d, SUM(amount_minor) total')->groupBy('d')->pluck('total', 'd');
        $series = [];
        for ($i = self::DAYS - 1; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $series[] = ['label' => Carbon::parse($day)->format('j M'), 'title' => Carbon::parse($day)->format('D j M'), 'count' => (int) ($byDay[$day] ?? 0)];
        }

        $stats = [
            'revenue' => $this->money->formatMoney($revenueMain, $currency),
            'revenue_count' => (int) Payment::query()->where('status', 'fulfilled')->where('fulfilled_at', '>=', $since)->count(),
            'coins_sold' => (int) Payment::query()->where('status', 'fulfilled')->where('purpose', 'coins')->where('fulfilled_at', '>=', $since)->sum('coins'),
            'active_subs' => (int) Subscription::query()->active()->count(),
            'queued_subs' => (int) Subscription::query()->where('status', 'queued')->count(),
            'coins_liability' => (int) Wallet::query()->sum('balance'),
            'coins_withdrawable' => (int) Wallet::query()->sum('withdrawable'),
            'reviews' => (int) Payment::query()->where('status', 'review')->count(),
            'promo_reviews' => (int) AdCampaign::query()->where('review_status', 'pending')->count(),
            'referrals_7d' => (int) Referral::query()->where('status', 'rewarded')->where('rewarded_at', '>=', now()->subDays(7))->count(),
        ];

        $week = now()->subDays(7);
        $flags = [
            'mismatch' => Payment::query()->where('status', 'failed')->where('updated_at', '>=', $week)->where('meta->reason', 'amount_mismatch')->orderByDesc('id')->limit(10)->get(),
            'shortfall' => Payment::query()->where('status', 'refunded')->where('refunded_at', '>=', $since)->whereNotNull('meta->shortfall')->orderByDesc('id')->limit(10)->get(),
            'undelivered' => Payment::query()->where('status', 'paid')->orderBy('paid_at')->limit(10)->get(),
            'callback_errors' => PaymentEvent::query()->whereNull('processed_at')->whereNotNull('error')->where('created_at', '>=', $week)->orderByDesc('id')->limit(10)->get(),
        ];

        return view('admin.money.index', [
            'stats' => $stats,
            'revenue' => $revenue,
            'series' => $series,
            'flags' => $flags,
            'warnings' => $this->warnings(),
            'currency' => $currency,
            'days' => self::DAYS,
        ]);
    }

    /** @return list<array{text: string, href: ?string}> */
    private function warnings(): array
    {
        $out = [];
        $settings = route('admin.settings').'#paid';

        if (! $this->money->enabled()) {
            $out[] = ['text' => 'Paid features are switched off: nobody can buy, promote or refer until the master switch is on.', 'href' => $settings];
        }
        if ((bool) AppSetting::get('manual_enabled') && $this->payments->manualMethods() === []) {
            $out[] = ['text' => 'Manual payments are on but no JazzCash / EasyPaisa / bank instructions are filled in, so the method is hidden.', 'href' => $settings];
        }
        if ((bool) AppSetting::get('stripe_enabled') && ! filled(config('services.stripe.secret'))) {
            $out[] = ['text' => 'Stripe is on but the secret key is missing.', 'href' => $settings];
        }
        if ((bool) AppSetting::get('stripe_enabled') && filled(config('services.stripe.secret')) && ! filled(config('services.stripe.webhook_secret'))) {
            $out[] = ['text' => 'Stripe has no webhook signing secret: payments are only confirmed by polling.', 'href' => $settings];
        }
        if ((bool) AppSetting::get('paypal_enabled') && (! filled(config('services.paypal.client_id')) || ! filled(config('services.paypal.secret')))) {
            $out[] = ['text' => 'PayPal is on but the client ID or secret is missing.', 'href' => $settings];
        }
        if ((bool) AppSetting::get('play_enabled') && ! app(GoogleServiceAccount::class)->configured()) {
            $out[] = ['text' => 'Google Play is on but the service-account JSON is missing or not valid: purchases cannot be verified.', 'href' => $settings];
        }

        $lastRun = Cache::get(ChatDoctor::SCHEDULER_HEARTBEAT_KEY);
        $stale = ! is_string($lastRun) || Carbon::parse($lastRun)->lt(now()->subHours(2));
        if ($stale) {
            $out[] = ['text' => 'The scheduler has not run in the last 2 hours: plans will not expire, monthly coins and sweeps will not run. Check the cron / schedule:work.', 'href' => null];
        }

        return $out;
    }
}

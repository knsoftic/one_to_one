<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\PaymentException;
use App\Http\Controllers\Controller;
use App\Models\CoinTransaction;
use App\Models\Payment;
use App\Services\AdminAuditService;
use App\Services\Payments\PayPalGateway;
use App\Services\Payments\PlayGateway;
use App\Services\Payments\StripeGateway;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin → Money → Payments (Y2): the review queue for manual transfers, every payment's history,
 * refunds and delivery retries, and the "Test connection" check of each gateway.
 */
class PaymentController extends Controller
{
    public const STATUS_TABS = ['review' => 'To review', 'paid' => 'Paid, not delivered', 'pending' => 'Pending', 'fulfilled' => 'Delivered', 'failed' => 'Failed', 'rejected' => 'Rejected', 'refunded' => 'Refunded', 'cancelled' => 'Cancelled', 'all' => 'All'];

    public function __construct(private readonly PaymentService $payments, private readonly AdminAuditService $audit) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(self::STATUS_TABS))],
            'gateway' => ['nullable', Rule::in(array_keys(Payment::GATEWAYS))],
            'purpose' => ['nullable', Rule::in(Payment::PURPOSES)],
            'q' => ['nullable', 'string', 'max:100'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $filters['status'] = $filters['status'] ?? 'review';

        $query = Payment::query()->with(['user', 'plan', 'coinPack', 'reviewer'])
            ->when($filters['status'] !== 'all', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['gateway'] ?? null, fn ($q, $gateway) => $q->where('gateway', $gateway))
            ->when($filters['purpose'] ?? null, fn ($q, $purpose) => $q->where('purpose', $purpose))
            ->when($filters['date'] ?? null, fn ($q, $date) => $q->whereDate('created_at', $date))
            ->when(trim((string) ($filters['q'] ?? '')), fn ($q, $term) => $this->search($q, $term))
            ->orderBy($filters['status'] === 'review' ? 'created_at' : 'id', $filters['status'] === 'review' ? 'asc' : 'desc');

        $counts = Payment::query()->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status')->all();

        return view('admin.payments.index', [
            'payments' => $query->paginate(25)->withQueryString(),
            'filters' => $filters,
            'counts' => $counts,
            'tabs' => self::STATUS_TABS,
        ]);
    }

    public function show(Payment $payment): View
    {
        $payment->load(['user', 'plan', 'coinPack', 'reviewer', 'subscription', 'events' => fn ($q) => $q->orderBy('id')]);

        // The same transfer id on another payment is the classic fake-screenshot pattern.
        $duplicates = $payment->proof_ref
            ? Payment::query()->where('gateway', 'manual')->where('proof_ref', $payment->proof_ref)->where('id', '!=', $payment->id)->orderByDesc('id')->limit(5)->get()
            : collect();

        $history = $payment->user_id
            ? Payment::query()->where('user_id', $payment->user_id)->where('id', '!=', $payment->id)->with(['plan', 'coinPack'])->orderByDesc('id')->limit(10)->get()
            : collect();
        $ledger = $payment->user_id
            ? CoinTransaction::query()->where('user_id', $payment->user_id)->orderByDesc('id')->limit(10)->get()
            : collect();

        return view('admin.payments.show', [
            'payment' => $payment,
            'duplicates' => $duplicates,
            'history' => $history,
            'ledger' => $ledger,
            'reported' => $this->reportedAmount($payment),
            'hasProof' => $payment->proof_path !== null && Storage::disk('local')->exists($payment->proof_path),
        ]);
    }

    /** The transfer screenshot, streamed from the private disk; every view is audited. */
    public function proof(Request $request, Payment $payment): StreamedResponse
    {
        abort_unless($payment->proof_path && Storage::disk('local')->exists($payment->proof_path), 404);

        $this->audit->record($request->user(), 'payment.proof_viewed', $payment, "Viewed the payment screenshot of payment #{$payment->id}".($payment->user ? " ({$payment->user->name})" : ''));

        return Storage::disk('local')->response($payment->proof_path, 'payment-'.$payment->id.'-proof.'.pathinfo($payment->proof_path, PATHINFO_EXTENSION), [
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function approve(Request $request, Payment $payment): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:200']]);

        return $this->act(fn () => $this->payments->approve($payment, $request->user(), $data['note'] ?? null), $payment,
            fn () => $payment->status === 'fulfilled' ? 'Payment approved and delivered.' : 'Payment approved, but delivery failed — see the flag and press Retry.');
    }

    public function reject(Request $request, Payment $payment): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:200']]);

        return $this->act(fn () => $this->payments->reject($payment, $request->user(), $data['note']), $payment, fn () => 'Payment rejected; the person was told why.');
    }

    /**
     * Money went (or goes) back: coins are clawed back, a plan is revoked. `via_gateway` asks
     * Stripe/PayPal to refund first; manual and Play refunds happen outside the app.
     */
    public function refund(Request $request, Payment $payment): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:200'], 'via_gateway' => ['nullable', 'boolean']]);
        $viaGateway = $request->boolean('via_gateway') && in_array($payment->gateway, ['stripe', 'paypal'], true);

        return $this->act(fn () => $this->payments->reverse($payment, 'admin_refund', $request->user(), $data['note'], $viaGateway), $payment, function () use ($payment, $viaGateway) {
            $shortfall = (int) ($payment->meta['shortfall'] ?? 0);

            return 'Payment refunded'.($viaGateway ? ' through '.Payment::GATEWAYS[$payment->gateway] : '').'.'
                .($shortfall > 0 ? " {$shortfall} coins had already been spent and could not be taken back." : '');
        });
    }

    public function retry(Request $request, Payment $payment): RedirectResponse
    {
        return $this->act(fn () => $this->payments->retry($payment, $request->user()), $payment, fn () => 'Delivered.');
    }

    /** POST settings.pay-check/{gateway} from the Test connection buttons → {ok, message}. */
    public function check(string $gateway): JsonResponse
    {
        $result = match ($gateway) {
            'stripe' => app(StripeGateway::class)->check(),
            'paypal' => app(PayPalGateway::class)->check(),
            'play' => app(PlayGateway::class)->check(),
            default => ['ok' => false, 'message' => 'Unknown gateway.'],
        };

        return response()->json($result, 200, ['Cache-Control' => 'no-store']);
    }

    /* ------------------------------------------------------------------ */

    /** Run a service call and turn its outcome into a flash on the payment page. */
    private function act(callable $action, Payment $payment, callable $success): RedirectResponse
    {
        try {
            $action();
        } catch (PaymentException $e) {
            return redirect()->route('admin.payments.show', $payment)->with('error', $e->getMessage());
        }

        return redirect()->route('admin.payments.show', $payment)->with('status', $success());
    }

    /** Search by payment id / uuid / gateway refs / typed transfer id, or by the person. */
    private function search($query, string $term)
    {
        $query->where(function ($q) use ($term) {
            $q->where('uuid', $term)->orWhere('gateway_ref', $term)->orWhere('gateway_capture_ref', $term)->orWhere('proof_ref', $term);
            if (ctype_digit($term)) {
                $q->orWhere('id', (int) $term)->orWhere('user_id', (int) $term);
            }
            $like = str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
            $q->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like)->orWhere('username', 'like', $like)->orWhere('phone', 'like', '%'.ltrim($term, '+').'%'));
        });
    }

    /** What the gateway said it took, for the snapshot-vs-reported flag on the detail page. */
    private function reportedAmount(Payment $payment): ?array
    {
        $meta = $payment->meta ?? [];
        if (isset($meta['reported']) && is_array($meta['reported'])) {
            $r = $meta['reported'];
            $minor = isset($r['amount_minor']) ? (int) $r['amount_minor'] : (isset($r['amount']) ? (int) round((float) $r['amount'] * 100) : null);

            return $minor === null ? null : ['amount_minor' => $minor, 'currency' => strtoupper((string) ($r['currency'] ?? '')), 'mismatch' => true];
        }
        if (isset($meta['stripe']['amount_total'])) {
            return ['amount_minor' => (int) $meta['stripe']['amount_total'], 'currency' => strtoupper((string) ($meta['stripe']['currency'] ?? '')), 'mismatch' => false];
        }
        if (isset($meta['paypal']['amount']['value'])) {
            return ['amount_minor' => (int) round((float) $meta['paypal']['amount']['value'] * 100), 'currency' => strtoupper((string) ($meta['paypal']['amount']['currency_code'] ?? '')), 'mismatch' => false];
        }
        if (isset($meta['google']['priceAmountMicros'])) {
            return ['amount_minor' => (int) round(((int) $meta['google']['priceAmountMicros']) / 10000), 'currency' => strtoupper((string) ($meta['google']['priceCurrencyCode'] ?? '')), 'mismatch' => false];
        }

        return null;
    }
}

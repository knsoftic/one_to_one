<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Models\User;
use App\Services\ReferralService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin → Money → Referrals (Y2): who invites the most, sign-ups that look like one person with
 * many SIMs, everything that was reversed and why, and the Void action.
 */
class ReferralController extends Controller
{
    public const SUSPICIOUS_MIN = 3;

    public function __construct(private readonly ReferralService $referrals) {}

    public function index(Request $request): View
    {
        $status = in_array($request->query('status'), Referral::STATUSES, true) ? $request->query('status') : null;

        $top = Referral::query()->select('referrer_id')
            ->selectRaw('COUNT(*) AS rewarded, SUM(referrer_coins) AS coins')
            ->where('status', 'rewarded')->groupBy('referrer_id')
            ->orderByDesc('rewarded')->orderByDesc('coins')->limit(10)->get();
        $people = User::query()->whereKey($top->pluck('referrer_id'))->get()->keyBy('id');

        // Rewarded or pending sign-ups that share one connection in the last 7 days.
        $groups = Referral::query()->select('ip_hash')->selectRaw('COUNT(*) AS n, MAX(created_at) AS last_at')
            ->whereNotNull('ip_hash')->where('created_at', '>=', now()->subDays(7))
            ->groupBy('ip_hash')->havingRaw('COUNT(*) >= ?', [self::SUSPICIOUS_MIN])
            ->orderByDesc('n')->limit(20)->get();
        $suspicious = $groups->map(fn ($g) => [
            'ip_hash' => $g->ip_hash,
            'count' => (int) $g->n,
            'last_at' => $g->last_at,
            'rows' => Referral::query()->with(['referrer:id,name,username', 'referred:id,name,username'])
                ->where('ip_hash', $g->ip_hash)->where('created_at', '>=', now()->subDays(7))->orderByDesc('id')->limit(10)->get(),
        ]);

        $rows = Referral::query()->with(['referrer:id,name,username', 'referred:id,name,username'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')->paginate(25)->withQueryString();

        $counts = Referral::query()->select('status')->selectRaw('COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');

        return view('admin.referrals.index', [
            'top' => $top->map(fn ($t) => ['user' => $people[$t->referrer_id] ?? null, 'rewarded' => (int) $t->rewarded, 'coins' => (int) $t->coins])->filter(fn ($t) => $t['user']),
            'suspicious' => $suspicious,
            'rows' => $rows,
            'status' => $status,
            'counts' => ['all' => (int) $counts->sum()] + collect(Referral::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)])->all(),
            'voidReasons' => Referral::VOID_REASONS,
            'enabled' => $this->referrals->enabled(),
            'coinsVoided' => (int) DB::table('coin_transactions')->where('type', 'referral_void')->sum(DB::raw('-amount')),
        ]);
    }

    public function void(Request $request, Referral $referral): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:160']]);

        if ($referral->status === 'void') {
            return back()->with('toast_error', 'This referral was already reversed.');
        }

        $this->referrals->void($referral, 'admin', $request->user(), $data['note']);

        return back()->with('status', 'Referral reversed and the coins taken back.');
    }
}

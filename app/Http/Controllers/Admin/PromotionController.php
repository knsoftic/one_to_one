<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\PromotionException;
use App\Http\Controllers\Controller;
use App\Models\AdCampaign;
use App\Services\PromotionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin → Promotions (Y2): the review queue of what people paid coins to promote, and the
 * approve / reject / stop actions.
 */
class PromotionController extends Controller
{
    public const TABS = [
        'pending' => ['Under review', ['pending']],
        'active' => ['Running', ['active']],
        'completed' => ['Finished', ['completed', 'stopped']],
        'rejected' => ['Rejected', ['rejected']],
    ];

    public function __construct(private readonly PromotionService $promotions) {}

    public function index(Request $request): View
    {
        $tab = (string) $request->query('tab', 'pending');
        if (! isset(self::TABS[$tab])) {
            $tab = 'pending';
        }

        $counts = AdCampaign::query()->promotions()->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status');
        $tabCounts = [];
        foreach (self::TABS as $key => [, $statuses]) {
            $tabCounts[$key] = (int) collect($statuses)->sum(fn ($s) => (int) ($counts[$s] ?? 0));
        }

        // The queue is oldest-first so nobody waits longer than they need to; the rest newest-first.
        $query = AdCampaign::query()->promotions()->with('owner')->whereIn('status', self::TABS[$tab][1]);
        $query = $tab === 'pending' ? $query->orderBy('id') : $query->orderByDesc('id');

        return view('admin.promotions.index', [
            'tab' => $tab,
            'tabs' => self::TABS,
            'counts' => $tabCounts,
            'campaigns' => $query->paginate(20)->withQueryString(),
        ]);
    }

    public function show(AdCampaign $campaign): View
    {
        abort_unless($campaign->isPromotion(), 404);
        $campaign->load(['owner', 'reviewer']);
        $stats = $this->promotions->stats($campaign);

        return view('admin.promotions.show', [
            'campaign' => $campaign,
            'stats' => $stats,
            'days' => array_map(fn ($d) => ['label' => $d['label'], 'title' => $d['day'], 'count' => $d['views']], $stats['days']),
            'target' => $this->targetLink($campaign),
        ]);
    }

    public function approve(Request $request, AdCampaign $campaign): RedirectResponse
    {
        abort_unless($campaign->isPromotion(), 404);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:200']]);

        try {
            $this->promotions->approve($request->user(), $campaign, $data['note'] ?? null);
        } catch (PromotionException $e) {
            return back()->withErrors(['promotion' => $e->getMessage()]);
        }

        return redirect()->route('admin.promotions.show', $campaign)->with('status', 'The promotion is now running.');
    }

    public function reject(Request $request, AdCampaign $campaign): RedirectResponse
    {
        abort_unless($campaign->isPromotion(), 404);
        $data = $request->validate(['note' => ['required', 'string', 'max:200']], ['note.required' => 'Tell the person why it was not approved.']);

        try {
            $this->promotions->reject($request->user(), $campaign, $data['note']);
        } catch (PromotionException $e) {
            return back()->withErrors(['promotion' => $e->getMessage()]);
        }

        return redirect()->route('admin.promotions.show', $campaign)->with('status', 'The promotion was rejected and the coins refunded.');
    }

    public function stop(Request $request, AdCampaign $campaign): RedirectResponse
    {
        abort_unless($campaign->isPromotion(), 404);
        $data = $request->validate(['refund' => ['nullable', Rule::in(['0', '1', 'true', 'false', 'on'])]]);
        $refund = $request->boolean('refund');

        try {
            $campaign = $this->promotions->stop($campaign, $request->user(), 'admin', $refund);
        } catch (PromotionException $e) {
            return back()->withErrors(['promotion' => $e->getMessage()]);
        }

        return redirect()->route('admin.promotions.show', $campaign)->with('status', $refund
            ? sprintf('The promotion was stopped; %s unused coins went back to the owner.', number_format($campaign->coins_refunded))
            : 'The promotion was stopped without a refund.');
    }

    /** Where the admin can look at what is being promoted. */
    private function targetLink(AdCampaign $campaign): ?array
    {
        return match ($campaign->kind) {
            'status' => ['label' => 'Status update', 'url' => route('admin.statuses'), 'external' => false],
            'channel' => ['label' => 'Channel', 'url' => route('admin.channels.show', $campaign->target_id), 'external' => false],
            'community' => ['label' => 'Community', 'url' => route('admin.communities.show', $campaign->target_id), 'external' => false],
            'business' => ['label' => 'Business profile', 'url' => route('admin.users.show', $campaign->target_id), 'external' => false],
            default => ['label' => parse_url($campaign->target_url, PHP_URL_HOST) ?: 'Link', 'url' => $campaign->target_url, 'external' => true],
        };
    }
}

<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientCoinsException;
use App\Exceptions\PromotionException;
use App\Exceptions\WalletFrozenException;
use App\Models\AdCampaign;
use App\Models\AppSetting;
use App\Services\CoinService;
use App\Services\PromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Settings › Promote (Y2): what I can promote, my promotions and their stats, the quote, the
 * submit, and the web fallback link a promoted card opens.
 */
class PromotionController extends Controller
{
    public function __construct(
        private readonly PromotionService $promotions,
        private readonly CoinService $coins,
    ) {}

    /** Everything the Promote screen needs in one go. */
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->promotions->enabled(), 404);
        $user = $request->user();

        $placements = [];
        foreach (PromotionService::KINDS as $kind) {
            $placements[$kind] = $this->promotions->placementsFor($kind);
        }

        return response()->json([
            'targets' => $this->promotions->targets($user),
            'rates' => $this->promotions->rates(),
            'min' => $this->promotions->minCoins(),
            'max' => $this->promotions->maxCoins(),
            'max_active' => $this->promotions->maxActive(),
            'placements' => $placements,
            'auto_approve' => (bool) AppSetting::get('promo_auto_approve'),
            'balance' => $this->coins->summary($user)['balance'],
            'frozen' => (bool) $this->coins->wallet($user)->frozen,
            'promotions' => $this->promotions->listFor($user)->map(fn (AdCampaign $p) => $this->promotions->row($p))->values()->all(),
        ]);
    }

    /** "≈ N views for C coins" while the person moves the budget. */
    public function quote(Request $request): JsonResponse
    {
        abort_unless($this->promotions->enabled(), 404);
        $data = $request->validate([
            'kind' => ['required', Rule::in(PromotionService::KINDS)],
            'coins' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        return response()->json($this->promotions->quote($data['kind'], (int) $data['coins']));
    }

    /** Submit a promotion (multipart: the card image is optional). */
    public function store(Request $request): JsonResponse
    {
        abort_unless($this->promotions->enabled(), 404);
        $data = $request->validate([
            'kind' => ['required', Rule::in(PromotionService::KINDS)],
            'target_id' => ['nullable', 'integer'],
            'coins' => ['required', 'integer', 'min:1', 'max:1000000'],
            'title' => ['nullable', 'string', 'max:80'],
            'body' => ['nullable', 'string', 'max:200'],
            'cta_label' => ['nullable', 'string', 'max:24'],
            'url' => ['nullable', 'string', 'max:600'],
            'client_token' => ['required', 'string', 'max:36'],
            'image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:3072'],
        ]);

        try {
            $campaign = $this->promotions->create($request->user(), $data, $request->file('image'));
        } catch (PromotionException|WalletFrozenException $e) {
            return response()->json($e->toArray(), 422);
        } catch (InsufficientCoinsException $e) {
            return response()->json($e->toArray(), 422);
        }

        return response()->json([
            'promotion' => $this->promotions->row($campaign),
            'balance' => $this->coins->summary($request->user())['balance'],
        ], $campaign->wasRecentlyCreated ? 201 : 200);
    }

    /** One promotion with its last 30 days and placements — the owner only. */
    public function show(Request $request, AdCampaign $campaign): JsonResponse
    {
        abort_unless($this->promotions->enabled(), 404);
        $this->ensureOwner($request, $campaign);

        return response()->json($this->promotions->stats($campaign));
    }

    /** Stop a running promotion (or withdraw a pending one): the unused coins come back. */
    public function stop(Request $request, AdCampaign $campaign): JsonResponse
    {
        abort_unless($this->promotions->enabled(), 404);
        $this->ensureOwner($request, $campaign);

        try {
            $campaign = $this->promotions->stop($campaign, $request->user(), 'user');
        } catch (PromotionException $e) {
            return response()->json($e->toArray(), 422);
        }

        return response()->json([
            'promotion' => $this->promotions->row($campaign),
            'balance' => $this->coins->summary($request->user())['balance'],
        ]);
    }

    /**
     * The web fallback of a promoted status / business card: renders the chat page with the
     * target to open, the way a channel or community invite link does.
     */
    public function go(Request $request, AdCampaign $campaign): View
    {
        // Only a promotion an admin approved and that is (or has just been) running may send
        // anyone anywhere. Without this, a rejected or still-pending link promotion would be a
        // redirect to any address the submitter likes, wearing this app's own domain.
        abort_unless(
            $campaign->isPromotion()
                && $campaign->review_status === 'approved'
                && in_array($campaign->status, ['active', 'completed'], true),
            404,
        );

        $open = $this->promotions->openPayload($campaign, $request->user());
        if ($open === null) {
            // A status or business promotion's web link is this very page, so falling back to
            // target_url once there is nothing left to open would reload this page for ever.
            $open = $campaign->isInternal() ? ['type' => 'gone'] : ['type' => 'url', 'url' => $campaign->target_url];
        }

        return view('chat.index', [
            'user' => $request->user(),
            'initialConversationId' => null,
            'openTarget' => $open,
        ]);
    }

    private function ensureOwner(Request $request, AdCampaign $campaign): void
    {
        abort_unless($campaign->isPromotion() && (int) $campaign->owner_id === (int) $request->user()->getKey(), 404);
    }
}

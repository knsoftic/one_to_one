<?php

namespace App\Services;

use App\Exceptions\InsufficientCoinsException;
use App\Exceptions\PromotionException;
use App\Exceptions\WalletFrozenException;
use App\Http\Resources\UserResource;
use App\Models\AdCampaign;
use App\Models\AppSetting;
use App\Models\BusinessProfile;
use App\Models\CoinTransaction;
use App\Models\Community;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\LinkPreview;
use App\Models\Status;
use App\Models\User;
use App\Notifications\MoneyNotification;
use App\Support\AdPlacement;
use App\Support\HostResolver;
use App\Support\SafeFetcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Promotions (Y2): a person pays coins to show their status, channel, community, business, a
 * custom card or a link as a "Promoted" card in the app. A promotion is an AdCampaign owned by a
 * user; serving, placements and stats are the same as the admin's house ads. Coins are held when
 * the promotion is submitted and refunded (fully or the unused share) when it is rejected or
 * stopped early. All coin movement goes through CoinService.
 */
class PromotionService
{
    public const KINDS = ['status', 'channel', 'community', 'business', 'card', 'link'];

    /** Kinds that open inside the app (and may be auto-approved). */
    public const INTERNAL_KINDS = ['status', 'channel', 'community', 'business'];

    /** What `ad_campaigns.target_type` holds per kind. */
    public const TARGET_TYPES = ['status' => 'status', 'channel' => 'conversation', 'community' => 'community', 'business' => 'user'];

    /** Where each kind runs by default (always ∩ promo_placements ∩ AdPlacement::enabled()). */
    public const DEFAULT_PLACEMENTS = [
        'status' => ['status_list', 'chat_list'],
        'channel' => ['channels', 'chat_list'],
        'community' => ['channels', 'chat_list'],
        'business' => ['chat_list'],
        'card' => ['chat_list'],
        'link' => ['chat_list'],
    ];

    public const STATUS_LABELS = [
        'pending' => 'Under review',
        'active' => 'Running',
        'completed' => 'Finished',
        'stopped' => 'Stopped',
        'rejected' => 'Not approved',
    ];

    private const IMAGE_DIR = 'ads/promo';

    public function __construct(
        private readonly CoinService $coins,
        private readonly AdminAuditService $audit,
        private readonly MonetisationService $money,
        private readonly SafeFetcher $fetcher,
        private readonly HostResolver $resolver,
        private readonly ImageService $images,
    ) {}

    public function enabled(): bool
    {
        return $this->money->promoteEnabled();
    }

    /** Coins per 1,000 views, per kind. */
    public function rates(): array
    {
        $rates = [];
        foreach (self::KINDS as $kind) {
            $rates[$kind] = max(1, (int) AppSetting::get('promo_rate_'.$kind));
        }

        return $rates;
    }

    public function minCoins(): int
    {
        return max(1, (int) AppSetting::get('promo_min_coins'));
    }

    public function maxCoins(): int
    {
        return max($this->minCoins(), (int) AppSetting::get('promo_max_coins'));
    }

    public function maxActive(): int
    {
        return max(1, (int) AppSetting::get('promo_max_active'));
    }

    /** How many views a budget buys: `intdiv(coins * 1000, rate)` — always in the platform's favour. */
    public function quote(string $kind, int $coins): array
    {
        $rate = $this->rates()[$kind] ?? max(1, (int) AppSetting::get('promo_rate_link'));
        $coins = max(0, $coins);

        return [
            'kind' => $kind,
            'coins' => $coins,
            'rate' => $rate,
            'views' => intdiv($coins * 1000, $rate),
            'min' => $this->minCoins(),
            'max' => $this->maxCoins(),
        ];
    }

    /**
     * What this person can promote: their live status updates, channels and communities they
     * are an admin of, and their business profile. Card and link need no target.
     *
     * @return list<array{kind: string, id: int, title: string, subtitle: ?string, image: ?string, background: ?string, already_promoted: bool, expires_at: ?string}>
     */
    public function targets(User $user): array
    {
        $out = [];

        foreach (Status::query()->active()->where('user_id', $user->getKey())->orderBy('created_at')->get() as $status) {
            if (! $this->promotableStatus($status)) {
                continue;
            }
            $out[] = [
                'kind' => 'status',
                'id' => $status->id,
                'title' => 'Status by '.$user->name,
                'subtitle' => $status->preview(120) ?: ucfirst($status->type),
                'image' => $status->attachment ? route('statuses.media', [$status, 'variant' => 'thumbnail'], false) : null,
                'background' => $status->type === Status::TYPE_TEXT ? ($status->background ?: 'teal') : null,
                'already_promoted' => $this->alreadyPromoted('status', $status->id),
                'expires_at' => $status->expires_at?->toIso8601String(),
            ];
        }

        foreach ($this->adminChannels($user)->get() as $channel) {
            $out[] = [
                'kind' => 'channel',
                'id' => $channel->id,
                'title' => (string) $channel->name,
                'subtitle' => $channel->description,
                'image' => $channel->groupAvatarUrl(),
                'background' => null,
                'already_promoted' => $this->alreadyPromoted('conversation', $channel->id),
                'expires_at' => null,
            ];
        }

        foreach ($this->adminCommunities($user)->get() as $community) {
            $out[] = [
                'kind' => 'community',
                'id' => $community->id,
                'title' => (string) $community->name,
                'subtitle' => $community->description,
                'image' => $community->avatarUrl(),
                'background' => null,
                'already_promoted' => $this->alreadyPromoted('community', $community->id),
                'expires_at' => null,
            ];
        }

        if ($business = $user->businessProfile()->first()) {
            $out[] = [
                'kind' => 'business',
                'id' => $user->getKey(),
                'title' => (string) $user->name,
                'subtitle' => trim(($business->publicPayload()['category_label'] ?? '').($business->description ? ' · '.$business->description : '')) ?: null,
                'image' => $user->avatar_url,
                'background' => null,
                'already_promoted' => $this->alreadyPromoted('user', $user->getKey()),
                'expires_at' => null,
            ];
        }

        return $out;
    }

    /** The placements a kind may run in: its defaults ∩ the admin's promo_placements ∩ what is switched on. */
    public function placementsFor(string $kind): array
    {
        $allowed = AppSetting::get('promo_placements');
        $allowed = is_array($allowed) ? $allowed : AdPlacement::keys();

        return array_values(array_intersect(self::DEFAULT_PLACEMENTS[$kind] ?? ['chat_list'], $allowed, AdPlacement::enabled()));
    }

    /**
     * Submit a promotion: validate, build the card from the target, insert the campaign row and
     * hold the coins — the row first, then the debit, in one transaction, so a failed debit
     * leaves no row behind. A repeated `client_token` returns the same campaign.
     *
     * @param  array{kind: string, target_id?: ?int, coins: int, title?: ?string, body?: ?string, cta_label?: ?string, url?: ?string, client_token: string}  $data
     *
     * @throws PromotionException|WalletFrozenException|InsufficientCoinsException
     */
    public function create(User $user, array $data, ?UploadedFile $image = null): AdCampaign
    {
        if (! $this->enabled()) {
            throw new PromotionException('disabled', 'Promotions are not available right now.');
        }

        $token = (string) ($data['client_token'] ?? '');
        if ($token !== '' && ($existing = $this->byToken($user, $token))) {
            return $existing;
        }

        if ($this->coins->wallet($user)->frozen) {
            throw new WalletFrozenException;
        }

        $kind = (string) ($data['kind'] ?? '');
        if (! in_array($kind, self::KINDS, true)) {
            throw new PromotionException('kind', 'Choose what to promote.');
        }

        $coins = (int) ($data['coins'] ?? 0);
        if ($coins < $this->minCoins() || $coins > $this->maxCoins()) {
            throw new PromotionException('coins', sprintf('Choose a budget between %s and %s coins.', number_format($this->minCoins()), number_format($this->maxCoins())));
        }

        $running = AdCampaign::query()->promotions()->where('owner_id', $user->getKey())->whereIn('status', ['pending', 'active'])->count();
        if ($running >= $this->maxActive()) {
            throw new PromotionException('max_active', sprintf('You already have %d promotions running. Wait for one to finish or stop it first.', $running));
        }

        // Nowhere to show it means nothing to sell: never take coins for views that cannot happen.
        $placements = $this->placementsFor($kind);
        if ($placements === []) {
            throw new PromotionException('placements', 'Promotions are not being shown on any screen at the moment. Please try again later.');
        }

        // What the card says and where it goes, worked out from the target.
        $card = $this->cardFor($user, $kind, $data, $image);

        $quote = $this->quote($kind, $coins);
        $auto = (bool) AppSetting::get('promo_auto_approve') && in_array($kind, self::INTERNAL_KINDS, true);

        $attributes = [
            'name' => ucfirst($kind).' · '.$card['title'],
            'status' => $auto ? 'active' : 'pending',
            'review_status' => $auto ? 'approved' : 'pending',
            'starts_at' => $auto ? now() : null,
            'ends_at' => $card['ends_at'],
            'title' => $card['title'],
            'body' => $card['body'],
            'image_path' => $card['image_path'],
            'cta_label' => $card['cta'],
            'target_url' => $card['target_url'],
            'sponsor' => Str::limit((string) $user->name, 60, ''),
            'countries' => null,
            'segments' => null,
            'min_age' => null,
            'max_age' => null,
            'gender' => null,
            'placements' => $placements,
            'per_user_daily_cap' => max(1, (int) AppSetting::get('promo_daily_cap')),
            'weight' => max(1, (int) AppSetting::get('promo_weight')),
            'owner_id' => $user->getKey(),
            'kind' => $kind,
            'target_type' => $card['target_type'],
            'target_id' => $card['target_id'],
            'client_token' => $token !== '' ? $token : null,
            'rate_per_1000' => $quote['rate'],
            'coins_spent' => $coins,
            'view_budget' => $quote['views'],
        ];

        try {
            $campaign = DB::transaction(function () use ($user, $attributes, $token, $coins) {
                if ($token !== '' && ($existing = $this->byToken($user, $token))) {
                    return $existing;
                }

                try {
                    $campaign = AdCampaign::query()->create($attributes);
                } catch (UniqueConstraintViolationException $e) {
                    // The same submit arrived twice at once: the first one wins.
                    if ($token !== '' && ($existing = $this->byToken($user, $token))) {
                        return $existing;
                    }
                    throw $e;
                }

                // Status and business cards open through promotions.go, which needs the id.
                if ($campaign->target_url === self::GO_PLACEHOLDER) {
                    $campaign->forceFill(['target_url' => route('promotions.go', $campaign)])->save();
                }

                // Insert first, debit second: a debit that throws rolls the row back.
                $this->coins->debit($user, $coins, 'promotion_hold', $campaign, "promo:{$campaign->id}:hold", 'Promotion: '.$campaign->title, ['kind' => $campaign->kind, 'views' => $campaign->view_budget, 'rate' => $campaign->rate_per_1000]);

                return $campaign;
            });
        } catch (Throwable $e) {
            // The row is gone; the copied card image must go too.
            if ($attributes['image_path']) {
                Storage::disk('public')->delete($attributes['image_path']);
            }
            throw $e;
        }

        if ($campaign->wasRecentlyCreated) {
            $campaign->refresh();
            $user->notify(new MoneyNotification($auto ? 'promotion_approved' : 'promotion_submitted', [
                'title' => $auto ? 'Your promotion is running' : 'Promotion submitted',
                'body' => $auto
                    ? sprintf('"%s" is now being shown — about %s views for %s coins.', $campaign->title, number_format($campaign->view_budget), number_format($coins))
                    : sprintf('"%s" is waiting for review. %s coins are on hold until it is approved.', $campaign->title, number_format($coins)),
                'tab' => 'promote',
            ]));
        }

        return $campaign;
    }

    /** Admin: put a pending promotion live. */
    public function approve(User $admin, AdCampaign $promo, ?string $note = null): AdCampaign
    {
        $promo = DB::transaction(function () use ($admin, $promo, $note) {
            $locked = $this->lock($promo);
            if ($locked->status !== 'pending') {
                throw new PromotionException('not_pending', 'Only a promotion under review can be approved.');
            }

            $locked->forceFill([
                'status' => 'active',
                'review_status' => 'approved',
                'starts_at' => now(),
                'reviewed_by' => $admin->getKey(),
                'reviewed_at' => now(),
                'review_note' => $note ? mb_substr($note, 0, 200) : null,
            ])->save();

            return $locked;
        });

        $this->audit->record($admin, 'promotion.approved', $promo, sprintf('Approved the promotion "%s" of %s', $promo->title, $promo->owner?->name ?? 'a deleted account'), $this->meta($promo));
        $promo->owner?->notify(new MoneyNotification('promotion_approved', [
            'title' => 'Your promotion was approved',
            'body' => sprintf('"%s" is now being shown — about %s views.', $promo->title, number_format((int) $promo->view_budget)),
            'tab' => 'promote',
        ]));

        return $promo;
    }

    /** Admin: refuse a pending promotion; every coin goes back. */
    public function reject(User $admin, AdCampaign $promo, string $note): AdCampaign
    {
        $promo = DB::transaction(function () use ($admin, $promo, $note) {
            $locked = $this->lock($promo);
            if ($locked->status !== 'pending') {
                throw new PromotionException('not_pending', 'Only a promotion under review can be rejected.');
            }

            $locked->forceFill([
                'status' => 'rejected',
                'review_status' => 'rejected',
                'stop_reason' => 'rejected',
                'completed_at' => now(),
                'reviewed_by' => $admin->getKey(),
                'reviewed_at' => now(),
                'review_note' => mb_substr($note, 0, 200),
            ])->save();

            $this->refund($locked, full: true);

            return $locked;
        });

        $this->audit->record($admin, 'promotion.rejected', $promo, sprintf('Rejected the promotion "%s" of %s: %s', $promo->title, $promo->owner?->name ?? 'a deleted account', $note), $this->meta($promo) + ['note' => $note]);
        $promo->owner?->notify(new MoneyNotification('promotion_rejected', [
            'title' => 'Your promotion was not approved',
            'body' => sprintf('"%s": %s. Your %s coins are back in your wallet.', $promo->title, rtrim($note, '.'), number_format($promo->coins_spent)),
            'tab' => 'promote',
        ]));

        return $promo;
    }

    /**
     * Stop a running promotion (by its owner, an admin or the system when the target vanished).
     * A pending one stopped by its owner is a withdrawal: everything is refunded. Otherwise the
     * unused share comes back — unless an admin decided not to refund.
     */
    public function stop(AdCampaign $promo, ?User $by, string $reason, bool $refund = true): AdCampaign
    {
        $promo = DB::transaction(function () use ($promo, $reason, $refund) {
            $locked = $this->lock($promo);
            if (! in_array($locked->status, ['pending', 'active'], true)) {
                throw new PromotionException('not_running', 'This promotion is not running.');
            }
            $wasPending = $locked->status === 'pending';

            $locked->forceFill([
                'status' => 'stopped',
                'completed_at' => now(),
                'stop_reason' => $reason,
            ])->save();

            if ($refund) {
                $this->refund($locked, full: $wasPending);
            } else {
                // Nothing comes back, and nothing may come back later either.
                $locked->forceFill(['refunded_at' => now()])->save();
            }

            return $locked;
        });

        $owner = $promo->owner;
        if ($by && $by->isAdmin() && $by->getKey() !== $promo->owner_id) {
            $this->audit->record($by, 'promotion.stopped', $promo, sprintf('Stopped the promotion "%s" of %s%s', $promo->title, $owner?->name ?? 'a deleted account', $refund ? '' : ' without a refund'), $this->meta($promo) + ['refund' => $refund, 'refunded' => $promo->coins_refunded]);
        }
        if ($owner && (! $by || $by->getKey() !== $owner->getKey())) {
            $owner->notify(new MoneyNotification('promotion_stopped', [
                'title' => 'Your promotion was stopped',
                'body' => sprintf('"%s" stopped after %s views%s.', $promo->title, number_format((int) $promo->impressions), match (true) {
                    $reason === 'target_gone' => ' because what it promoted is no longer there',
                    $reason === 'placements_off' => ' because there is no longer a screen it can be shown on',
                    $reason === 'owner_banned' => ' because your account was suspended',
                    $reason === 'admin' => ' by the admin',
                    default => '',
                }).($promo->coins_refunded > 0 ? sprintf(' %s unused coins are back in your wallet.', number_format($promo->coins_refunded)) : ''),
                'tab' => 'promote',
            ]));
        }

        return $promo;
    }

    /**
     * The view budget is used up. Called by AdService inside the transaction that counted the
     * last view, so the promotion is completed exactly once by exactly that view.
     */
    public function complete(AdCampaign $promo): void
    {
        $done = AdCampaign::query()->whereKey($promo->id)->where('status', 'active')
            ->update(['status' => 'completed', 'completed_at' => now(), 'stop_reason' => 'budget']);

        if ($done === 0) {
            return;
        }

        $fresh = AdCampaign::query()->find($promo->id);
        $fresh?->owner?->notify(new MoneyNotification('promotion_finished', [
            'title' => 'Your promotion finished',
            'body' => sprintf('"%s" reached %s views and %s taps.', $fresh->title, number_format((int) $fresh->impressions), number_format((int) $fresh->clicks)),
            'tab' => 'promote',
        ]));
    }

    /**
     * Give coins back once: everything (`$full`) or the unused share
     * `coins_spent − ceil(impressions × rate / 1000)`, clamped to [0, coins_spent].
     */
    public function refund(AdCampaign $promo, bool $full): ?CoinTransaction
    {
        return DB::transaction(function () use ($promo, $full) {
            $locked = $this->lock($promo);
            if ($locked->refunded_at !== null) {
                return null;
            }

            $used = (int) ceil((int) $locked->impressions * (int) $locked->rate_per_1000 / 1000);
            $amount = $full ? (int) $locked->coins_spent : max(0, (int) $locked->coins_spent - $used);
            $amount = min($amount, (int) $locked->coins_spent);

            $row = null;
            $owner = $locked->owner;
            $original = $this->coins->findByKey("promo:{$locked->id}:hold");
            if ($amount > 0 && $owner && $original) {
                $row = $this->coins->refund($owner, $amount, 'promotion_refund', $locked, "promo:{$locked->id}:refund", $original, ($full ? 'Refund: ' : 'Unused coins: ').$locked->title);
            }

            $locked->forceFill(['coins_refunded' => $row ? $amount : 0, 'refunded_at' => now()])->save();
            $promo->coins_refunded = $locked->coins_refunded;
            $promo->refunded_at = $locked->refunded_at;

            return $row;
        });
    }

    /** The status expired, or the channel / community / business profile was deleted. */
    public function stopForTarget(string $targetType, int $targetId, string $reason): int
    {
        $count = 0;
        $rows = AdCampaign::query()->promotions()
            ->where('target_type', $targetType)->where('target_id', $targetId)
            ->whereIn('status', ['pending', 'active'])
            ->get();

        foreach ($rows as $promo) {
            try {
                $this->stop($promo, null, $reason);
                $count++;
            } catch (PromotionException) {
                // Already finished in the meantime.
            }
        }

        return $count;
    }

    /** Account deletion: nothing of this person keeps showing. */
    public function stopAllFor(User $user, string $reason): int
    {
        $count = 0;
        foreach (AdCampaign::query()->promotions()->where('owner_id', $user->getKey())->whereIn('status', ['pending', 'active'])->get() as $promo) {
            try {
                $this->stop($promo, null, $reason);
                $count++;
            } catch (PromotionException) {
                // Already finished in the meantime.
            }
        }

        return $count;
    }

    /**
     * Scheduled: promotions that can no longer do what they were paid for — their target quietly
     * disappeared, or the admin has since switched off every screen they were booked for, so
     * they would sit "running" for ever without ever being picked. Both stop with the unused
     * share refunded and the owner told why.
     *
     * Ads being switched off altogether is a pause, not a reason to stop anybody's promotion, so
     * the placement check is skipped while `ads_enabled` is off.
     */
    public function sweepTargets(): int
    {
        $count = 0;
        $adsOn = (bool) AppSetting::get('ads_enabled');

        AdCampaign::query()->promotions()->whereIn('status', ['pending', 'active'])
            ->chunkById(100, function (Collection $rows) use (&$count, $adsOn) {
                foreach ($rows as $promo) {
                    $reason = match (true) {
                        in_array($promo->kind, self::INTERNAL_KINDS, true) && ! $this->targetExists($promo) => 'target_gone',
                        $adsOn && $promo->placementList() === [] => 'placements_off',
                        default => null,
                    };
                    if ($reason === null) {
                        continue;
                    }
                    try {
                        $this->stop($promo, null, $reason);
                        $count++;
                    } catch (PromotionException) {
                        // Already finished.
                    }
                }
            });

        return $count;
    }

    /**
     * My promotions, newest first, with what the Promote screen shows per row.
     *
     * @return Collection<int, AdCampaign>
     */
    public function listFor(User $user): Collection
    {
        return AdCampaign::query()->promotions()->where('owner_id', $user->getKey())->orderByDesc('id')->get();
    }

    /** One row of "My promotions" (also the base of `stats()`). */
    public function row(AdCampaign $promo): array
    {
        return [
            'id' => $promo->id,
            'kind' => $promo->kind,
            'kind_label' => AdCampaign::KINDS[$promo->kind] ?? ucfirst((string) $promo->kind),
            'status' => $promo->status,
            'status_label' => self::STATUS_LABELS[$promo->status] ?? ucfirst((string) $promo->status),
            'review_note' => $promo->review_status === 'rejected' ? $promo->review_note : null,
            'stop_reason' => $promo->stop_reason,
            'card' => $promo->payload() + ['url' => $promo->target_url, 'format' => 'row'],
            'impressions' => (int) $promo->impressions,
            'clicks' => (int) $promo->clicks,
            'ctr' => $promo->ctr(),
            'view_budget' => (int) $promo->view_budget,
            'remaining' => $promo->remainingViews() ?? 0,
            'coins_spent' => (int) $promo->coins_spent,
            'coins_refunded' => (int) $promo->coins_refunded,
            'rate_per_1000' => (int) $promo->rate_per_1000,
            'can_stop' => in_array($promo->status, ['pending', 'active'], true),
            'created_at' => $promo->created_at?->toIso8601String(),
            'starts_at' => $promo->starts_at?->toIso8601String(),
            'ends_at' => $promo->ends_at?->toIso8601String(),
            'completed_at' => $promo->completed_at?->toIso8601String(),
        ];
    }

    /** The detail screen: the row plus the last 30 days and where the card was seen. */
    public function stats(AdCampaign $promo): array
    {
        $byDay = DB::table('ad_stats')->where('campaign_id', $promo->id)
            ->where('day', '>=', today()->subDays(29)->toDateString())
            ->get()->keyBy(fn ($r) => Carbon::parse($r->day)->toDateString());

        $days = [];
        for ($i = 29; $i >= 0; $i--) {
            $day = today()->subDays($i);
            $row = $byDay->get($day->toDateString());
            $days[] = ['day' => $day->toDateString(), 'label' => $day->format('j M'), 'views' => (int) ($row->impressions ?? 0), 'taps' => (int) ($row->clicks ?? 0)];
        }

        $placements = DB::table('ad_placement_stats')->where('campaign_id', $promo->id)->orderByDesc('impressions')->get()
            ->map(fn ($r) => ['placement' => $r->placement, 'label' => AdPlacement::label($r->placement), 'views' => (int) $r->impressions, 'taps' => (int) $r->clicks])
            ->values()->all();

        return $this->row($promo) + [
            'views' => (int) $promo->impressions,
            'taps' => (int) $promo->clicks,
            'budget' => (int) $promo->view_budget,
            'spent' => (int) $promo->coins_spent,
            'refunded' => (int) $promo->coins_refunded,
            'days' => $days,
            'placements' => $placements,
            'booked' => array_map(fn ($key) => AdPlacement::label($key), $promo->placementList()),
        ];
    }

    /**
     * What the app opens when a promoted card is tapped (ads.tap) or the web fallback link is
     * followed (promotions.go). Null for card, link and house ads: those just open the URL.
     */
    public function openPayload(AdCampaign $promo, User $viewer): ?array
    {
        if (! $promo->isPromotion() || ! $promo->isInternal() || $promo->target_id === null) {
            return null;
        }

        switch ($promo->kind) {
            case 'status':
                $status = Status::query()->active()->with('user')->find($promo->target_id);
                if (! $status || ! $status->user) {
                    return null;
                }
                // The promotion is what lets people outside the owner's contacts see this update
                // (canView() knows about it), but it never overrules the rest: somebody the owner
                // blocked, or left off the update's own privacy list, still sees nothing — and
                // neither does anyone once the promotion is no longer granting the view.
                if (! app(StatusService::class)->canView($status, $viewer)) {
                    return null;
                }

                return [
                    'type' => 'status',
                    'user' => (new UserResource($status->user))->resolve(request()),
                    'status' => app(StatusService::class)->payload($status, $viewer),
                ];

            case 'channel':
                $channel = Conversation::query()->where('type', Conversation::TYPE_CHANNEL)->whereNull('ended_at')->find($promo->target_id);

                return $channel ? ['type' => 'channel', 'id' => $channel->id] : null;

            case 'community':
                $community = Community::query()->find($promo->target_id);
                if (! $community) {
                    return null;
                }
                if (! $community->invite_token) {
                    $community->forceFill(['invite_token' => Str::random(22)])->save();
                }

                return [
                    'type' => 'community',
                    'invite' => ['valid' => true, 'token' => $community->invite_token] + app(CommunityService::class)->payload($community, $viewer, withGroups: false),
                ];

            case 'business':
                $owner = User::query()->find($promo->target_id);
                if (! $owner || ! $owner->isActive() || ! $owner->businessProfile()->exists()) {
                    return null;
                }

                return ['type' => 'business', 'user_id' => $owner->getKey()];
        }

        return null;
    }

    /** A promoted status is visible to people outside the owner's contacts while it runs. */
    public function grantsStatusView(Status $status, User $viewer): bool
    {
        if ((int) $status->user_id === (int) $viewer->getKey()) {
            return false;
        }

        return AdCampaign::query()->promotions()
            ->where('kind', 'status')->where('target_type', 'status')->where('target_id', $status->getKey())
            ->where('review_status', 'approved')->whereIn('status', ['active', 'completed'])
            ->exists();
    }

    /* ------------------------------------------------------------------ */
    /* Internals */
    /* ------------------------------------------------------------------ */

    /**
     * Title, text, image, link and target of the card, from the thing being promoted. Ownership
     * is checked here: you can only promote your own status, business, and channels or
     * communities you administer.
     *
     * @return array{title: string, body: ?string, cta: string, image_path: ?string, target_url: string, target_type: ?string, target_id: ?int, ends_at: ?Carbon}
     */
    private function cardFor(User $user, string $kind, array $data, ?UploadedFile $image): array
    {
        $title = $this->text($data['title'] ?? null, 80);
        $body = $this->text($data['body'] ?? null, 200);
        $cta = $this->text($data['cta_label'] ?? null, 24);
        $targetId = isset($data['target_id']) && $data['target_id'] !== '' ? (int) $data['target_id'] : null;
        $imagePath = null;
        $endsAt = null;

        switch ($kind) {
            case 'status':
                $status = $targetId ? Status::query()->active()->where('user_id', $user->getKey())->find($targetId) : null;
                if (! $status) {
                    throw new PromotionException('target', 'This status update is not yours or has expired.');
                }
                if (! $this->promotableStatus($status)) {
                    throw new PromotionException('target', 'A status update you only share with some people cannot be promoted — a promoted update is shown to everyone.');
                }
                $this->assertNotPromoted('status', $status->id);
                $title = $title ?? 'Status by '.$user->name;
                $body = $body ?? ($status->body ? Str::limit(str((string) $status->body)->squish(), 200, '…') : null);
                $cta = $cta ?? 'View status';
                if ($status->attachment) {
                    $thumb = app(StatusService::class)->mediaPath($status, 'thumbnail') ?? app(StatusService::class)->mediaPath($status);
                    $imagePath = $thumb ? $this->copyImage($thumb) : null;
                }
                $endsAt = $status->expires_at;
                $targetType = 'status';
                $targetUrl = null; // promotions.go — needs the campaign id, filled in below
                break;

            case 'channel':
                $channel = $targetId ? $this->adminChannels($user)->find($targetId) : null;
                if (! $channel) {
                    throw new PromotionException('target', 'You can only promote a channel you are an admin of.');
                }
                $this->assertNotPromoted('conversation', $channel->id);
                if (! $channel->invite_token) {
                    $channel->forceFill(['invite_token' => Str::random(22)])->save();
                }
                $title = $title ?? (string) $channel->name;
                $body = $body ?? $channel->description;
                $cta = $cta ?? 'Follow channel';
                $imagePath = $channel->avatar ? $this->copyPublic($channel->avatar) : null;
                $targetType = 'conversation';
                $targetId = $channel->id;
                $targetUrl = route('channels.link', $channel->invite_token);
                break;

            case 'community':
                $community = $targetId ? $this->adminCommunities($user)->find($targetId) : null;
                if (! $community) {
                    throw new PromotionException('target', 'You can only promote a community you are an admin of.');
                }
                $this->assertNotPromoted('community', $community->id);
                if (! $community->invite_token) {
                    $community->forceFill(['invite_token' => Str::random(22)])->save();
                }
                $title = $title ?? (string) $community->name;
                $body = $body ?? $community->description;
                $cta = $cta ?? 'Join community';
                $imagePath = $community->avatar ? $this->copyPublic($community->avatar) : null;
                $targetType = 'community';
                $targetId = $community->id;
                $targetUrl = route('communities.join.show', $community->invite_token);
                break;

            case 'business':
                $business = $user->businessProfile()->first();
                if (! $business) {
                    throw new PromotionException('target', 'Turn on your business profile first.');
                }
                $this->assertNotPromoted('user', $user->getKey());
                $label = $business->publicPayload()['category_label'] ?? '';
                $title = $title ?? (string) $user->name;
                $body = $body ?? (trim($label.($business->description ? ' · '.$business->description : '')) ?: null);
                $cta = $cta ?? 'Message';
                $imagePath = $user->profile_image ? $this->copyPublic($user->profile_image) : null;
                $targetType = 'user';
                $targetId = $user->getKey();
                $targetUrl = null; // promotions.go
                break;

            case 'card':
                $targetUrl = $this->safeUrl($data['url'] ?? null);
                if ($title === null) {
                    throw new PromotionException('title', 'Give your card a headline.');
                }
                $cta = $cta ?? 'Learn more';
                if ($image) {
                    $imagePath = $this->storeUpload($image);
                }
                $targetType = null;
                $targetId = null;
                break;

            default: // link
                $targetUrl = $this->safeUrl($data['url'] ?? null);
                $preview = ($title === null || $body === null) ? $this->linkPreview($targetUrl) : null;
                $title = $title ?? $this->text($preview?->title, 80) ?? parse_url($targetUrl, PHP_URL_HOST) ?? 'Link';
                $body = $body ?? $this->text($preview?->description, 200);
                $cta = $cta ?? 'Open link';
                if ($image) {
                    $imagePath = $this->storeUpload($image);
                } elseif ($preview?->image) {
                    $disk = Storage::disk(config('chat.uploads.disk'));
                    $imagePath = $disk->exists($preview->image) ? $this->copyImage($disk->path($preview->image)) : null;
                }
                $targetType = null;
                $targetId = null;
        }

        return [
            'title' => $title,
            'body' => $body,
            'cta' => $cta ?? 'Learn more',
            'image_path' => $imagePath,
            'target_url' => $targetUrl ?? self::GO_PLACEHOLDER,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'ends_at' => $endsAt,
        ];
    }

    /**
     * Status and business cards open through `promotions.go`, which needs the campaign id — the
     * URL is written right after the row is inserted (see `create()`).
     */
    private const GO_PLACEHOLDER = 'promotions.go';

    private function byToken(User $user, string $token): ?AdCampaign
    {
        $existing = AdCampaign::query()->where('client_token', $token)->first();
        if ($existing && (int) $existing->owner_id !== (int) $user->getKey()) {
            throw new PromotionException('token', 'This submission belongs to another account.');
        }

        return $existing;
    }

    private function lock(AdCampaign $promo): AdCampaign
    {
        return AdCampaign::query()->whereKey($promo->id)->lockForUpdate()->firstOrFail();
    }

    private function meta(AdCampaign $promo): array
    {
        return ['campaign' => $promo->id, 'owner' => $promo->owner_id, 'coins' => (int) $promo->coins_spent, 'kind' => $promo->kind];
    }

    private function alreadyPromoted(string $targetType, int $targetId): bool
    {
        return AdCampaign::query()->promotions()->where('target_type', $targetType)->where('target_id', $targetId)->whereIn('status', ['pending', 'active'])->exists();
    }

    private function assertNotPromoted(string $targetType, int $targetId): void
    {
        if ($this->alreadyPromoted($targetType, $targetId)) {
            throw new PromotionException('already_promoted', 'This is already being promoted.');
        }
    }

    /** @return Builder<Conversation> */
    private function adminChannels(User $user): Builder
    {
        return Conversation::query()->where('type', Conversation::TYPE_CHANNEL)->whereNull('ended_at')
            ->whereHas('members', fn ($q) => $q->where('user_id', $user->getKey())->where('role', ConversationMember::ROLE_ADMIN)->whereNull('left_at'))
            ->orderBy('name');
    }

    /** @return Builder<Community> */
    private function adminCommunities(User $user): Builder
    {
        return Community::query()
            ->whereHas('announcement', fn ($q) => $q->whereNull('ended_at')->whereHas('members', fn ($m) => $m->where('user_id', $user->getKey())->where('role', ConversationMember::ROLE_ADMIN)->whereNull('left_at')))
            ->orderBy('name');
    }

    /**
     * A promoted status update is shown to everyone, and its text and picture are copied into the
     * card itself — so an update the owner deliberately narrowed to a list of people ("Only share
     * with…", or "My contacts except…" with somebody left out) may not be promoted at all. The
     * card would hand its contents to exactly the people who were meant not to see it.
     */
    private function promotableStatus(Status $status): bool
    {
        $restricted = array_map('intval', $status->privacy_user_ids ?? []);

        return match ($status->privacy) {
            Status::PRIVACY_ONLY => false,
            Status::PRIVACY_EXCEPT => $restricted === [],
            default => true,
        };
    }

    private function targetExists(AdCampaign $promo): bool
    {
        return match ($promo->kind) {
            'status' => Status::query()->active()->whereKey($promo->target_id)->exists(),
            'channel' => Conversation::query()->where('type', Conversation::TYPE_CHANNEL)->whereNull('ended_at')->whereKey($promo->target_id)->exists(),
            'community' => Community::query()->whereKey($promo->target_id)->whereHas('announcement', fn ($q) => $q->whereNull('ended_at'))->exists(),
            'business' => BusinessProfile::query()->where('user_id', $promo->target_id)->exists(),
            default => true,
        };
    }

    private function text(?string $value, int $limit): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    /**
     * A card or link URL that is safe to send people to: http/https, not on the admin's blocked
     * list, and not a host that resolves to a private or loopback address.
     */
    private function safeUrl(?string $url): string
    {
        $normalized = $this->fetcher->normalize((string) $url);
        if ($normalized === null) {
            throw new PromotionException('url', 'Enter a full web address that starts with http:// or https://.');
        }

        $host = strtolower(trim((string) parse_url($normalized, PHP_URL_HOST), '[]'));
        foreach ($this->blockedHosts() as $blocked) {
            if ($host === $blocked || str_ends_with($host, '.'.$blocked)) {
                throw new PromotionException('url', 'Links to this site are not allowed.');
            }
        }

        $addresses = $this->resolver->resolve($host);
        if ($addresses === []) {
            throw new PromotionException('url', "We couldn't reach that address. Check the link and try again.");
        }
        foreach ($addresses as $address) {
            if (! SafeFetcher::isPublicIp($address)) {
                throw new PromotionException('url', 'Links to private or local addresses are not allowed.');
            }
        }

        return $normalized;
    }

    /** @return list<string> */
    private function blockedHosts(): array
    {
        $raw = (string) AppSetting::get('promo_blocked_hosts');

        return collect(preg_split('/[\s,]+/', strtolower($raw)) ?: [])
            ->map(fn ($h) => trim($h, " \t\n\r\0\x0B./"))
            ->map(fn ($h) => preg_replace('~^https?://~', '', $h))
            ->filter()->values()->all();
    }

    private function linkPreview(string $url): ?LinkPreview
    {
        try {
            return app(LinkPreviewService::class)->preview($url);
        } catch (Throwable $e) {
            Log::info('Promotion link preview failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** A card image the person uploaded: re-encoded (strips metadata, caps the size) and stored publicly. */
    private function storeUpload(UploadedFile $image): ?string
    {
        try {
            $encoded = $this->images->reencode($image->getRealPath(), 1200, 'jpeg', 86);
            $path = self::IMAGE_DIR.'/'.Str::uuid().'.jpg';
            Storage::disk('public')->put($path, $encoded['binary']);

            return $path;
        } catch (Throwable $e) {
            throw new PromotionException('image', 'That image could not be read. Use a PNG, JPG or WebP picture.');
        }
    }

    /** A copy of an existing picture (status thumbnail, avatar, link preview) for the card. */
    private function copyImage(string $absolutePath): ?string
    {
        if (! is_file($absolutePath)) {
            return null;
        }
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION)) ?: 'jpg';
        $path = self::IMAGE_DIR.'/'.Str::uuid().'.'.$extension;
        $stream = @fopen($absolutePath, 'rb');
        if ($stream === false) {
            return null;
        }
        Storage::disk('public')->put($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return $path;
    }

    private function copyPublic(string $relativePath): ?string
    {
        $disk = Storage::disk('public');

        return $disk->exists($relativePath) ? $this->copyImage($disk->path($relativePath)) : null;
    }
}

<?php

namespace App\Models;

use App\Support\AdPlacement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * A house ad created in the admin panel (Y1). "Sponsored" cards shown in the app, booked
 * against one or more placements (chats list, status, channels, calls, inside a chat).
 */
class AdCampaign extends Model
{
    public const STATUSES = ['draft', 'active', 'paused'];

    /** Broad audience segments worked out from what people do in the app (see AdTargetingService). */
    public const SEGMENTS = [
        'business' => 'Business accounts',
        'active' => 'Very active users',
        'new' => 'New users (joined recently)',
        'groups' => 'In groups',
        'callers' => 'Make calls',
        'media' => 'Share lots of media',
    ];

    public const GENDERS = ['male' => 'Male', 'female' => 'Female'];

    /** Statuses a promotion (Y2) moves through; house ads keep STATUSES. */
    public const PROMO_STATUSES = ['pending', 'active', 'completed', 'stopped', 'rejected'];

    /**
     * Why a stopped promotion may still be tapped: the budget ran out, the owner stopped it, or
     * the system took it down. An admin stop ("take this down now") and a rejection are not here.
     */
    public const TAPPABLE_STOP_REASONS = ['budget', 'user', 'target_gone', 'placements_off'];

    /** What a user can promote (Y2); `house` is the admin's own ad. */
    public const KINDS = [
        'house' => 'House ad',
        'status' => 'Status',
        'channel' => 'Channel',
        'community' => 'Community',
        'business' => 'Business profile',
        'card' => 'Custom card',
        'link' => 'Website link',
    ];

    protected $fillable = [
        'name', 'status', 'title', 'body', 'image_path', 'cta_label', 'target_url', 'sponsor',
        'countries', 'segments', 'min_age', 'max_age', 'gender', 'placements',
        'per_user_daily_cap', 'weight', 'starts_at', 'ends_at', 'created_by',
        // Promotions (Y2)
        'owner_id', 'kind', 'target_type', 'target_id', 'client_token', 'review_status', 'review_note',
        'reviewed_by', 'reviewed_at', 'coins_spent', 'coins_refunded', 'rate_per_1000', 'view_budget',
        'completed_at', 'refunded_at', 'stop_reason',
    ];

    protected function casts(): array
    {
        return [
            'countries' => 'array',
            'segments' => 'array',
            'placements' => 'array',
            'min_age' => 'integer',
            'max_age' => 'integer',
            'per_user_daily_cap' => 'integer',
            'weight' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'coins_spent' => 'integer',
            'coins_refunded' => 'integer',
            'rate_per_1000' => 'integer',
            'view_budget' => 'integer',
            'reviewed_at' => 'datetime',
            'completed_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /* Promotions (Y2) — a campaign owned by a user. */

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPromotion(): bool
    {
        return $this->owner_id !== null;
    }

    /** Status, channel, community and business open inside the app; card and link leave it. */
    public function isInternal(): bool
    {
        return in_array($this->kind, ['status', 'channel', 'community', 'business'], true);
    }

    /** Views still to deliver, or null when unlimited (house ads). */
    public function remainingViews(): ?int
    {
        return $this->view_budget === null ? null : max(0, $this->view_budget - (int) $this->impressions);
    }

    /**
     * A tap on the last served card of a promotion that just finished still counts — but only
     * for a promotion an admin approved, and only when it stopped for a harmless reason. A
     * rejected one, or one an admin took down, must never send anybody anywhere again, however
     * long its card stays on somebody's screen.
     */
    public function acceptsTap(): bool
    {
        if (! $this->isPromotion()) {
            return $this->isLive();
        }

        if ($this->review_status !== 'approved') {
            return false;
        }

        if ($this->isLive() || $this->status === 'completed') {
            return true;
        }

        return $this->status === 'stopped' && in_array((string) $this->stop_reason, self::TAPPABLE_STOP_REASONS, true);
    }

    public function scopeHouse(Builder $query): Builder
    {
        return $query->whereNull('owner_id');
    }

    public function scopePromotions(Builder $query): Builder
    {
        return $query->whereNotNull('owner_id');
    }

    public function views(): HasMany
    {
        return $this->hasMany(AdView::class, 'campaign_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }

    /** Active, within its schedule, and actually shows to people. */
    public function isLive(): bool
    {
        return $this->status === 'active'
            && ($this->starts_at === null || $this->starts_at->isPast())
            && ($this->ends_at === null || $this->ends_at->isFuture())
            && ($this->view_budget === null || (int) $this->impressions < $this->view_budget);
    }

    public function ctr(): float
    {
        return $this->impressions > 0 ? round($this->clicks / $this->impressions * 100, 2) : 0.0;
    }

    /**
     * The placements this ad may run in. For a house ad, no placement ticked means "wherever ads
     * are switched on" — the admin's shorthand for all of them. A promotion is different: its
     * placements were worked out when it was booked (PromotionService::placementsFor), so an
     * empty list means the admin has since switched off every screen it was allowed on — nowhere
     * left, never everywhere (a status card must not turn into the banner inside open chats).
     */
    public function placementList(): array
    {
        $chosen = $this->placements ?: ($this->isPromotion() ? [] : AdPlacement::keys());

        return array_values(array_intersect(AdPlacement::enabled(), $chosen));
    }

    public function runsIn(string $placement): bool
    {
        return in_array($placement, $this->placementList(), true);
    }

    /** What the app shows: never any targeting details. */
    public function payload(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'image' => $this->imageUrl(),
            'cta' => $this->cta_label,
            'sponsor' => $this->sponsor,
            // Promotions (Y2): the card says "Promoted · name" and may open inside the app.
            'kind' => $this->kind ?? 'house',
            'promoted' => $this->isPromotion(),
            'internal' => $this->isInternal(),
        ];
    }
}

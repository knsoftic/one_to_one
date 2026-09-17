<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * A house ad created in the admin panel (Y1). "Sponsored" cards shown in the app.
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

    protected $fillable = [
        'name', 'status', 'title', 'body', 'image_path', 'cta_label', 'target_url', 'sponsor',
        'countries', 'interests', 'min_age', 'max_age', 'gender', 'personalised_only',
        'per_user_daily_cap', 'weight', 'starts_at', 'ends_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'countries' => 'array',
            'interests' => 'array',
            'personalised_only' => 'boolean',
            'min_age' => 'integer',
            'max_age' => 'integer',
            'per_user_daily_cap' => 'integer',
            'weight' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
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
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    public function ctr(): float
    {
        return $this->impressions > 0 ? round($this->clicks / $this->impressions * 100, 2) : 0.0;
    }

    /** Whether this campaign can only run for users who turned personalised ads on. */
    public function needsConsent(): bool
    {
        return $this->personalised_only
            || ! empty($this->interests)
            || $this->min_age !== null
            || $this->max_age !== null
            || $this->gender !== null;
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
        ];
    }
}

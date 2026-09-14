<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone reported a person for spam or abuse (Phase 6, P6); reviewed by admins.
 */
class UserReport extends Model
{
    public const REASONS = [
        'spam' => 'Spam',
        'abuse' => 'Abusive or harassing',
        'fake' => 'Fake account or scam',
        'other' => 'Something else',
    ];

    public const STATUS_OPEN = 'open';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_REVIEWED, self::STATUS_DISMISSED];

    protected $fillable = ['reporter_id', 'reported_user_id', 'conversation_id', 'reason', 'details', 'evidence', 'blocked'];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'blocked' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function reportedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? ucfirst((string) $this->reason);
    }
}

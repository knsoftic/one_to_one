<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A status update (Phase 5): text, photo or video, visible for 24 hours.
 */
class Status extends Model
{
    public const TYPE_TEXT = 'text';

    public const TYPE_IMAGE = 'image';

    public const TYPE_VIDEO = 'video';

    /** Everyone in my contacts. */
    public const PRIVACY_CONTACTS = 'contacts';

    /** My contacts except the people chosen. */
    public const PRIVACY_EXCEPT = 'except';

    /** Only the people chosen. */
    public const PRIVACY_ONLY = 'only';

    public const PRIVACY_MODES = [self::PRIVACY_CONTACTS, self::PRIVACY_EXCEPT, self::PRIVACY_ONLY];

    /** Background colours of text updates (the app draws them). */
    public const BACKGROUNDS = ['teal', 'indigo', 'violet', 'rose', 'amber', 'emerald', 'sky', 'slate'];

    public const FONTS = 5;

    protected $fillable = [
        'user_id',
        'type',
        'body',
        'background',
        'font',
        'attachment',
        'attachment_mime',
        'attachment_size',
        'attachment_meta',
        'privacy',
        'privacy_user_ids',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'font' => 'integer',
            'attachment_size' => 'integer',
            'attachment_meta' => 'array',
            'privacy_user_ids' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(StatusView::class);
    }

    /** Updates that have not run out yet. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    public function isActive(): bool
    {
        return $this->expires_at?->isFuture() ?? false;
    }

    public function isOwnedBy(User|int $user): bool
    {
        return (int) $this->user_id === (int) ($user instanceof User ? $user->getKey() : $user);
    }

    /** A short line for chat quotes and notifications. */
    public function preview(int $limit = 100): string
    {
        return match ($this->type) {
            self::TYPE_IMAGE => '📷 '.($this->body ? str($this->body)->squish()->limit($limit) : 'Photo'),
            self::TYPE_VIDEO => '🎥 '.($this->body ? str($this->body)->squish()->limit($limit) : 'Video'),
            default => (string) str((string) $this->body)->squish()->limit($limit),
        };
    }
}

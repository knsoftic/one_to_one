<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * D8 — a backup ZIP: a person's chats (personal) or the whole server (server).
 */
class ChatBackup extends Model
{
    public const KIND_PERSONAL = 'personal';

    public const KIND_SERVER = 'server';

    public const STATUS_PENDING = 'pending';

    public const STATUS_WORKING = 'working';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'include_media' => 'boolean',
            'size' => 'integer',
            'stats' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isBusy(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_WORKING], true);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY && $this->path !== null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * What a person's apps show about their backup.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->isReady() || $this->status !== self::STATUS_READY ? $this->status : 'expired',
            'include_media' => $this->include_media,
            'size' => $this->size,
            'stats' => $this->stats,
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'download_url' => $this->isReady() && $this->kind === self::KIND_PERSONAL ? route('backups.download', $this, false) : null,
        ];
    }
}

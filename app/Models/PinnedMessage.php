<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PinnedMessage extends Model
{
    /** How long a pin lasts (seconds), as offered by WhatsApp. */
    public const DURATIONS = [86400, 604800, 2592000];

    /** Pins per chat; pinning another one replaces the oldest. */
    public const MAX_PER_CONVERSATION = 3;

    public const UPDATED_AT = null;

    protected $fillable = ['conversation_id', 'message_id', 'pinned_by', 'expires_at', 'created_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}

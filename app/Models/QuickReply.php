<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * X8 — a saved answer the business types with "/shortcut".
 */
class QuickReply extends Model
{
    public const MAX_PER_USER = 50;

    protected $fillable = ['shortcut', 'message'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array{id: int, shortcut: string, message: string} */
    public function toPayload(): array
    {
        return ['id' => $this->id, 'shortcut' => $this->shortcut, 'message' => $this->message];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone saw a status update (S2), and maybe reacted to it (S4).
 */
class StatusView extends Model
{
    public $timestamps = false;

    protected $fillable = ['status_id', 'user_id', 'viewed_at', 'reaction'];

    protected function casts(): array
    {
        return ['viewed_at' => 'datetime'];
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

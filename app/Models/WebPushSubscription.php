<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * X3 — one browser that receives push notifications for an account.
 */
class WebPushSubscription extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['endpoint', 'public_key', 'auth_token'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public static function hashSession(?string $sessionId): ?string
    {
        return $sessionId ? hash('sha256', 'web-push|'.$sessionId) : null;
    }
}

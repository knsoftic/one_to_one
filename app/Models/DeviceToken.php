<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An installed mobile app signed into an account. The plain access token is
 * shown to the app once; only its SHA-256 hash is stored. `fcm_token` is the
 * Firebase push token when the phone supports it.
 */
class DeviceToken extends Model
{
    protected $fillable = [
        'user_id',
        'token_hash',
        'fcm_token',
        'fcm_token_hash',
        'platform',
        'app_version',
        'last_used_at',
    ];

    protected $hidden = ['token_hash', 'fcm_token', 'fcm_token_hash'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

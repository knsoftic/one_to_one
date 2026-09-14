<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A QR code / code shown on a login page, approved from a signed-in phone (P10).
 */
class LoginLink extends Model
{
    protected $fillable = ['token_hash', 'code_hash', 'secret_hash', 'ip_address', 'user_agent', 'expires_at'];

    protected $hidden = ['token_hash', 'code_hash', 'secret_hash'];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'consumed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Waiting for a phone: not approved, not used, not expired. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('approved_by')->whereNull('consumed_at')->where('expires_at', '>', now());
    }
}

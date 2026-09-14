<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * G10 — A community: groups under one roof with an announcement group.
 */
class Community extends Model
{
    protected $fillable = ['name', 'description', 'avatar', 'created_by', 'invite_token'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Every group of the community, the announcement group included. */
    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function announcement(): HasOne
    {
        return $this->hasOne(Conversation::class)->where('is_announcement', true);
    }

    public function groups(): HasMany
    {
        return $this->conversations()->where('is_announcement', false)->whereNull('ended_at');
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar ? asset('storage/'.$this->avatar) : null;
    }

    public function initials(): string
    {
        $words = preg_split('/\s+/u', trim((string) $this->name)) ?: [];
        $letters = collect($words)->filter()->take(2)->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode('');

        return $letters !== '' ? $letters : '#';
    }

    public function hue(): int
    {
        return ((int) $this->getKey() * 71) % 360;
    }
}

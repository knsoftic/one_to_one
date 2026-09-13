<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sticker extends Model
{
    /** Stickers are 512×512 WebP, like WhatsApp's. */
    public const SIZE = 512;

    /** Oldest unused stickers are removed beyond this many per person. */
    public const MAX_PER_USER = 200;

    protected $fillable = ['user_id', 'path', 'hash', 'used_at'];

    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array{id:int, url:string}
     */
    public function toPayload(): array
    {
        return ['id' => $this->id, 'url' => route('stickers.image', $this, false)];
    }
}

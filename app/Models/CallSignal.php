<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A WebRTC signaling message (offer, answer or ICE candidate) for one device in a call.
 */
class CallSignal extends Model
{
    public const TYPE_OFFER = 'offer';

    public const TYPE_ANSWER = 'answer';

    public const TYPE_CANDIDATE = 'candidate';

    /** Microphone muted / camera switched off, shown to the other side. */
    public const TYPE_MEDIA = 'media';

    public const TYPES = [self::TYPE_OFFER, self::TYPE_ANSWER, self::TYPE_CANDIDATE, self::TYPE_MEDIA];

    public const UPDATED_AT = null;

    protected $fillable = [
        'call_id',
        'call_room_id',
        'sender_id',
        'recipient_id',
        'from_client',
        'to_client',
        'type',
        'payload',
    ];

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(bool $includeData = true): array
    {
        return [
            'id' => $this->id,
            'call_id' => $this->call_id,
            'call_room_id' => $this->call_room_id,
            'sender_id' => $this->sender_id,
            'type' => $this->type,
            'from_client' => $this->from_client,
            'to_client' => $this->to_client,
            'payload' => $includeData ? $this->payload : null,
        ];
    }
}

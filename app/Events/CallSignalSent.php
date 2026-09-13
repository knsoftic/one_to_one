<?php

namespace App\Events;

use App\Models\CallSignal;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * WebRTC signaling for the other participant. Small payloads (ICE candidates)
 * travel inside the event; large ones (session descriptions) exceed the
 * WebSocket message limit, so the device fetches them over HTTP instead.
 */
class CallSignalSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public const INLINE_LIMIT_BYTES = 6000;

    public function __construct(public CallSignal $signal) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->signal->recipient_id)];
    }

    public function broadcastAs(): string
    {
        return 'call.signal';
    }

    public function broadcastWith(): array
    {
        return ['signal' => $this->signal->toPayload(strlen($this->signal->payload) <= self::INLINE_LIMIT_BYTES)];
    }
}

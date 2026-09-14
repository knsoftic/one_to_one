<?php

namespace App\Events;

use App\Models\CallRoom;
use App\Services\CallRoomService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * K6 — Someone was added to, joined or left a group call (or it ended).
 * Sent to everyone who is or was in it.
 */
class CallRoomUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public CallRoom $room) {}

    public function broadcastOn(): array
    {
        return $this->room->participants()->pluck('user_id')
            ->unique()
            ->map(fn ($id) => new PrivateChannel('App.Models.User.'.$id))
            ->values()
            ->all();
    }

    public function broadcastAs(): string
    {
        return 'call.room';
    }

    public function broadcastWith(): array
    {
        return ['room' => app(CallRoomService::class)->payload($this->room)];
    }
}

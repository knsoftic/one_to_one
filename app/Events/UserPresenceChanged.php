<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A user came online or went offline (with an accurate "last seen"), sent only
 * to the people allowed to see it (Phase 6, P1).
 */
class UserPresenceChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  list<int>  $recipientIds
     */
    public function __construct(
        public int $userId,
        public bool $isOnline,
        public ?string $lastSeen,
        public array $recipientIds = [],
    ) {}

    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel('App.Models.User.'.$id), array_values(array_unique($this->recipientIds)));
    }

    public function broadcastAs(): string
    {
        return 'user.presence';
    }

    public function broadcastWith(): array
    {
        return [
            'user' => [
                'id' => $this->userId,
                'is_online' => $this->isOnline,
                'last_seen' => $this->lastSeen,
            ],
        ];
    }
}

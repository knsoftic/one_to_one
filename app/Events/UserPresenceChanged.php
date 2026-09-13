<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A user came online or went offline (with an accurate "last seen").
 */
class UserPresenceChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $userId,
        public bool $isOnline,
        public ?string $lastSeen,
    ) {}

    public static function for(User $user): self
    {
        return new self($user->id, $user->isOnlineNow(), $user->last_seen?->toIso8601String());
    }

    public function broadcastOn(): array
    {
        return [new PresenceChannel('online')];
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

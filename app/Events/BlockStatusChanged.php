<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A user blocked or unblocked another user. Both sides update their
 * composer / header instantly.
 */
class BlockStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $blockerId,
        public int $blockedId,
        public bool $blocked,
        public ?int $conversationId = null,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->blockerId),
            new PrivateChannel('App.Models.User.'.$this->blockedId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'block.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'blocker_id' => $this->blockerId,
            'blocked_id' => $this->blockedId,
            'blocked' => $this->blocked,
            'conversation_id' => $this->conversationId,
        ];
    }
}

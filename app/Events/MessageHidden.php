<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A message was deleted "for me": only the user's own other tabs/devices hear about it.
 */
class MessageHidden implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public int $messageId,
        public int $conversationId,
        public int $userId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'message.hidden';
    }

    public function broadcastWith(): array
    {
        return ['id' => $this->messageId, 'conversation_id' => $this->conversationId];
    }
}

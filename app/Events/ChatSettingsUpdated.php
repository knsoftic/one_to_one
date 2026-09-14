<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A person changed how a chat appears in their list (pin, mute, archive…):
 * only their own other tabs and devices hear about it.
 */
class ChatSettingsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $userId, public int $conversationId) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'chat.settings';
    }

    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId];
    }
}

<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Disappearing messages reached their time and were removed for both people.
 */
class MessagesExpired implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  list<int>  $userIds
     * @param  list<int>  $messageIds
     */
    public function __construct(
        public int $conversationId,
        public array $userIds,
        public array $messageIds,
    ) {}

    public function broadcastOn(): array
    {
        return array_map(fn (int $id) => new PrivateChannel('App.Models.User.'.$id), array_values(array_unique($this->userIds)));
    }

    public function broadcastAs(): string
    {
        return 'messages.expired';
    }

    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversationId, 'ids' => $this->messageIds];
    }
}

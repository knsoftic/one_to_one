<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Messages were delivered to / seen by the receiver.
 * Sent to the original sender so their ✓ / ✓✓ ticks update instantly.
 */
class MessagesStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  list<int>  $messageIds
     */
    public function __construct(
        public int $conversationId,
        public int $senderId,
        public array $messageIds,
        public string $status,
        public string $at,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->senderId)];
    }

    public function broadcastAs(): string
    {
        return 'message.status';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'ids' => array_values($this->messageIds),
            'status' => $this->status,
            'at' => $this->at,
        ];
    }
}

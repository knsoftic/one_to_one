<?php

namespace App\Events;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A new message was stored. Broadcast to both participants' private channels
 * (the sender's other tabs/devices stay in sync; the sending tab is excluded).
 */
class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    public function broadcastOn(): array
    {
        // One channel when both are the same person ("Message yourself").
        return array_map(
            fn ($id) => new PrivateChannel('App.Models.User.'.$id),
            array_values(array_unique([(int) $this->message->receiver_id, (int) $this->message->sender_id])),
        );
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return ['message' => MessageResource::forBroadcast($this->message)];
    }
}

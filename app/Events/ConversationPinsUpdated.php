<?php

namespace App\Events;

use App\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pinned messages of a chat changed; both people reload the chat's pins
 * (what each of them may see differs, e.g. messages deleted "for me").
 */
class ConversationPinsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Conversation $conversation) {}

    public function broadcastOn(): array
    {
        // One channel when both are the same person ("Message yourself").
        return array_map(
            fn ($id) => new PrivateChannel('App.Models.User.'.$id),
            $this->conversation->audienceIds(),
        );
    }

    public function broadcastAs(): string
    {
        return 'conversation.pins';
    }

    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversation->id];
    }
}

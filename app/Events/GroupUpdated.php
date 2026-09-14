<?php

namespace App\Events;

use App\Models\Conversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A group's name, icon, description, settings or members changed (Phase 4).
 * Clients reload the group; people who were just removed are told as well.
 */
class GroupUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  list<int>  $alsoNotify  people no longer in the group who should still hear about it
     */
    public function __construct(public Conversation $conversation, public array $alsoNotify = []) {}

    public function broadcastOn(): array
    {
        return array_map(
            fn (int $id) => new PrivateChannel('App.Models.User.'.$id),
            array_values(array_unique([...$this->conversation->activeMemberIds(), ...$this->alsoNotify])),
        );
    }

    public function broadcastAs(): string
    {
        return 'group.updated';
    }

    public function broadcastWith(): array
    {
        return ['conversation_id' => $this->conversation->getKey()];
    }
}

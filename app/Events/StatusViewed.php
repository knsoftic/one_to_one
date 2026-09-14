<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone saw one of my status updates (S2): my view count goes up.
 */
class StatusViewed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $ownerId, public int $statusId, public int $viewsCount) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->ownerId)];
    }

    public function broadcastAs(): string
    {
        return 'status.viewed';
    }

    public function broadcastWith(): array
    {
        return ['status_id' => $this->statusId, 'views_count' => $this->viewsCount];
    }
}

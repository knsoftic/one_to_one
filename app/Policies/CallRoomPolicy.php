<?php

namespace App\Policies;

use App\Models\CallRoom;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CallRoomPolicy
{
    /**
     * Only people who were added to the group call can see it; everyone else gets a 404.
     */
    public function view(User $user, CallRoom $room): Response
    {
        return $room->participants()->where('user_id', $user->getKey())->exists()
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}

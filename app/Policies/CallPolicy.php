<?php

namespace App\Policies;

use App\Models\Call;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CallPolicy
{
    /**
     * Only the two participants can see or act on a call; everyone else gets a 404.
     */
    public function view(User $user, Call $call): Response
    {
        return $call->hasParticipant($user) ? Response::allow() : Response::denyAsNotFound();
    }
}

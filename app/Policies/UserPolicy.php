<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Administrative actions on user accounts.
 */
class UserPolicy
{
    public function manage(User $admin, User $user): Response
    {
        if (! $admin->isAdmin()) {
            return Response::deny('This area is restricted to administrators.');
        }

        if ($admin->is($user)) {
            return Response::deny('You cannot change your own account from the admin panel.');
        }

        if ($user->isAdmin()) {
            return Response::deny('Administrator accounts cannot be changed from the admin panel.');
        }

        return Response::allow();
    }
}

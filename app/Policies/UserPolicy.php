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
            return Response::deny('Administrator accounts cannot be changed from the admin panel. Remove their admin role first.');
        }

        return Response::allow();
    }

    /** Make someone an administrator, or take the role away from another administrator. */
    public function changeRole(User $admin, User $user): Response
    {
        if (! $admin->isAdmin()) {
            return Response::deny('This area is restricted to administrators.');
        }

        if ($admin->is($user)) {
            return Response::deny('You cannot change your own role.');
        }

        if ($user->isAdmin() && User::query()->where('role', User::ROLE_ADMIN)->where('status', User::STATUS_ACTIVE)->whereKeyNot($user->getKey())->doesntExist()) {
            return Response::deny('The app needs at least one administrator.');
        }

        return $user->isActive() || $user->isAdmin()
            ? Response::allow()
            : Response::deny('Only active accounts can become administrators.');
    }
}

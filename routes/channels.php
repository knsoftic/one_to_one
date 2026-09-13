<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channels
|--------------------------------------------------------------------------
|
| Authorization runs behind the "web", "auth" and "active" middleware
| (see bootstrap/app.php).
|
*/

// Private per-user channel: new messages, receipts, typing, notifications.
Broadcast::channel('App.Models.User.{id}', function (User $user, int $id) {
    return (int) $user->id === $id;
});

// Presence channel listing users who currently have the app open.
Broadcast::channel('online', function (User $user) {
    if (! $user->isActive()) {
        return false;
    }

    return [
        'id' => $user->id,
        'name' => $user->name,
        'username' => $user->username,
        'avatar_url' => $user->avatar_url,
        'initials' => $user->initials,
        'avatar_hue' => $user->avatar_hue,
    ];
});

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

// Presence (online / last seen) is sent on each person's private channel, only
// to the people their privacy settings allow (Phase 6) — there is no global list.

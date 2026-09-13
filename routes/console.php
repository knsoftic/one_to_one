<?php

use App\Models\User;
use App\Services\CallService;
use App\Services\PresenceService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console commands
|--------------------------------------------------------------------------
*/

Artisan::command('chat:make-admin {email : Email of an existing user}', function (string $email) {
    $user = User::query()->where('email', mb_strtolower($email))->first();

    if (! $user) {
        $this->error("No user found with email [{$email}].");

        return 1;
    }

    $user->forceFill(['role' => User::ROLE_ADMIN, 'status' => User::STATUS_ACTIVE])->save();
    $this->info("{$user->name} ({$user->email}) is now an administrator.");

    return 0;
})->purpose('Grant administrator access to an existing user');

Artisan::command('chat:sweep-presence', function (PresenceService $presence) {
    $count = $presence->sweepStale();
    $this->info("Marked {$count} inactive user(s) offline.");
})->purpose('Mark users without recent activity as offline');

Artisan::command('chat:expire-calls', function (CallService $calls) {
    $count = $calls->expireStale();
    $this->info("Closed {$count} unanswered or abandoned call(s).");
})->purpose('End calls nobody answered and calls whose devices disconnected');

/*
|--------------------------------------------------------------------------
| Scheduled tasks (run `php artisan schedule:work` or a cron entry)
|--------------------------------------------------------------------------
*/

Schedule::command('chat:sweep-presence')->everyMinute()->withoutOverlapping();
Schedule::command('chat:expire-calls')->everyMinute()->withoutOverlapping();

<?php

use App\Console\Commands\ChatDoctor;
use App\Models\LinkPreview;
use App\Models\User;
use App\Models\UserLogin;
use App\Services\BackupService;
use App\Services\BanService;
use App\Services\CallRoomService;
use App\Services\CallService;
use App\Services\DisappearingMessageService;
use App\Services\OtpService;
use App\Services\PresenceService;
use App\Services\StatusService;
use App\Services\ViewOnceService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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

Artisan::command('chat:expire-messages', function (DisappearingMessageService $disappearing) {
    $count = $disappearing->expire();
    $this->info("Removed {$count} disappearing message(s).");
})->purpose('Remove disappearing messages whose time is up');

Artisan::command('chat:expire-statuses', function (StatusService $statuses) {
    $count = $statuses->expire();
    $this->info("Removed {$count} status update(s) older than a day.");
})->purpose('Remove status updates whose 24 hours are over');

Artisan::command('chat:purge-view-once', function (ViewOnceService $viewOnce) {
    $count = $viewOnce->purge();
    $this->info("Removed the files of {$count} opened view once message(s).");
})->purpose('Remove media of view once messages after they were opened');

Artisan::command('chat:lift-bans', function (BanService $bans) {
    $count = $bans->liftEnded();
    $this->info("Lifted {$count} ban(s) whose time is up.");
})->purpose('Lift temporary bans that have ended');

Artisan::command('chat:expire-calls', function (CallService $calls, CallRoomService $rooms) {
    $count = $calls->expireStale();
    $rooms->expireAllStale();
    $this->info("Closed {$count} unanswered or abandoned call(s).");
})->purpose('End calls nobody answered and calls whose devices disconnected');

Artisan::command('chat:backups', function (BackupService $backups) {
    $made = $backups->runPending();
    $removed = $backups->prune();
    $this->info("Made {$made} waiting backup(s); removed {$removed} expired backup file(s).");
})->purpose('Make chat backups no queue worker picked up and remove expired ones');

/*
|--------------------------------------------------------------------------
| Scheduled tasks (run `php artisan schedule:work` or a cron entry)
|--------------------------------------------------------------------------
*/

// Lets `php artisan chat:doctor` confirm the cron task is running.
Schedule::call(fn () => Cache::put(ChatDoctor::SCHEDULER_HEARTBEAT_KEY, now()->toIso8601String(), now()->addDay()))
    ->everyMinute()
    ->name('chat-scheduler-heartbeat');
Schedule::command('chat:sweep-presence')->everyMinute()->withoutOverlapping();
Schedule::command('chat:expire-calls')->everyMinute()->withoutOverlapping();
Schedule::command('chat:expire-messages')->everyMinute()->withoutOverlapping();
Schedule::command('chat:purge-view-once')->everyMinute()->withoutOverlapping();
Schedule::command('chat:expire-statuses')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('chat:lift-bans')->everyFiveMinutes()->withoutOverlapping();
// Chat backups (D8): waiting ones when no queue worker runs, and expired files.
Schedule::command('chat:backups')->everyMinute()->withoutOverlapping(120)->runInBackground();
// SMS codes that expired more than a day ago (Phase 7).
Schedule::call(fn () => app(OtpService::class)->prune())->daily()->name('prune-otp-codes');
// Sign-in history older than it is kept for (admin panel → user → Devices).
Schedule::call(fn () => UserLogin::query()->where('created_at', '<', now()->subDays(UserLogin::KEEP_DAYS))->delete())->daily()->name('prune-user-logins');
// Link previews no message uses anymore (and their images).
Schedule::command('model:prune', ['--model' => [LinkPreview::class]])->daily();

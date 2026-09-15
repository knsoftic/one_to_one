<?php

namespace App\Listeners;

use App\Models\User;
use App\Models\UserLogin;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sign-in history for the admin panel: every sign-in (and how), wrong passwords for an
 * existing account, and sign-outs. Unknown accounts are never recorded.
 */
class RecordLoginActivity
{
    /** Route that signed someone in => how they signed in. */
    private const METHODS = [
        'login.attempt' => 'password',
        'register.store' => 'register',
        'login.phone.verify' => 'phone_code',
        'two-step.verify' => 'two_step',
        'login.qr.status' => 'qr',
    ];

    public function handleLogin(Login $event): void
    {
        $route = request()->route()?->getName();
        $method = self::METHODS[$route] ?? null;

        // Settings pages refresh the "remember me" cookie of this device: not a new sign-in.
        if ($method === null && $event->remember === false) {
            return;
        }

        $this->record($event->user, UserLogin::EVENT_LOGIN, $method ?? 'remembered');
    }

    public function handleFailed(Failed $event): void
    {
        $this->record($event->user, UserLogin::EVENT_FAILED, 'password');
    }

    public function handleLogout(Logout $event): void
    {
        $this->record($event->user, UserLogin::EVENT_LOGOUT, null);
    }

    private function record(mixed $user, string $event, ?string $method): void
    {
        if (! $user instanceof User || ! $user->exists) {
            return;
        }

        try {
            $request = request();
            UserLogin::query()->create([
                'user_id' => $user->getKey(),
                'event' => $event,
                'method' => $method,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500) ?: null,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // History must never stop anyone from signing in.
            Log::warning('Could not record sign-in activity: '.$e->getMessage());
        }
    }
}

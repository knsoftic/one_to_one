<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\TwoStepResetNotification;
use App\Support\UserAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Phase 6 — P7: two-step verification. A 6-digit PIN is asked when someone signs
 * in with the password on a browser that hasn't passed the PIN before.
 */
class TwoStepService
{
    public const COOKIE = 'two_step_device';

    public const SESSION_KEY = 'two_step';

    /** Minutes the password step stays valid while the PIN is asked. */
    public const PENDING_MINUTES = 10;

    public function enabled(User $user): bool
    {
        return $user->two_step_pin !== null;
    }

    public function enable(User $user, string $pin, Request $request): void
    {
        $user->forceFill(['two_step_pin' => Hash::make($pin), 'two_step_enabled_at' => now()])->save();
        // The browser that turns it on is trusted.
        $this->trust($user, $request);
    }

    public function changePin(User $user, string $pin): void
    {
        $user->forceFill(['two_step_pin' => Hash::make($pin)])->save();
    }

    public function disable(User $user): void
    {
        $user->forceFill(['two_step_pin' => null, 'two_step_enabled_at' => null])->save();
        $user->trustedDevices()->delete();
    }

    public function checkPin(User $user, string $pin): bool
    {
        return $user->two_step_pin !== null && Hash::check($pin, $user->two_step_pin);
    }

    /** Signing in with the password on this browser needs the PIN. */
    public function requiresChallenge(User $user, Request $request): bool
    {
        return $this->enabled($user) && ! $this->isTrusted($user, $request);
    }

    public function trust(User $user, Request $request): void
    {
        $token = Str::random(48);

        $user->trustedDevices()->create([
            'token_hash' => hash('sha256', $token),
            'name' => mb_substr(UserAgent::describe($request->userAgent()), 0, 120),
            'ip_address' => $request->ip(),
            'last_used_at' => now(),
        ]);

        Cookie::queue(Cookie::make(self::COOKIE, $user->getKey().'|'.$token, 60 * 24 * 365 * 2, null, null, null, true, false, 'lax'));
    }

    public function isTrusted(User $user, Request $request): bool
    {
        [$userId, $token] = array_pad(explode('|', (string) $request->cookie(self::COOKIE), 2), 2, '');

        if ((int) $userId !== (int) $user->getKey() || $token === '') {
            return false;
        }

        $device = $user->trustedDevices()->where('token_hash', hash('sha256', $token))->first();
        $device?->forceFill(['last_used_at' => now()])->save();

        return $device !== null;
    }

    /** "Ask for the PIN again on every browser". */
    public function forgetDevices(User $user): int
    {
        return $user->trustedDevices()->delete();
    }

    public function sendReset(User $user): void
    {
        $user->notify(new TwoStepResetNotification);
    }

    /* ------------------------------------------------------------------ */
    /* The sign-in waiting for the PIN */
    /* ------------------------------------------------------------------ */

    public function startChallenge(Request $request, User $user, bool $remember): void
    {
        $request->session()->put(self::SESSION_KEY, [
            'user_id' => $user->getKey(),
            'remember' => $remember,
            'expires_at' => now()->addMinutes(self::PENDING_MINUTES)->getTimestamp(),
        ]);
    }

    /**
     * @return array{user: User, remember: bool}|null
     */
    public function pending(Request $request): ?array
    {
        $pending = $request->session()->get(self::SESSION_KEY);

        if (! is_array($pending) || ($pending['expires_at'] ?? 0) < now()->getTimestamp()) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        $user = User::query()->whereKey($pending['user_id'] ?? 0)->active()->first();

        return $user ? ['user' => $user, 'remember' => (bool) ($pending['remember'] ?? false)] : null;
    }

    public function finishChallenge(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }
}

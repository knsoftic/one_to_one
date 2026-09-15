<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Bans from the admin panel: for a number of days or for good, with a reason the
 * banned person sees on the ban screen. Temporary bans end by themselves.
 */
class BanService
{
    /** Ban lengths offered in the admin panel, in days (null = permanent). */
    public const DURATIONS = [
        '1' => '1 day',
        '3' => '3 days',
        '7' => '7 days',
        '30' => '30 days',
        '90' => '90 days',
        'permanent' => 'Permanently',
    ];

    public const SESSION_KEY = 'ban';

    public function __construct(private readonly PresenceService $presence) {}

    public function ban(User $user, User $admin, ?int $days, string $reason): User
    {
        $user->forceFill([
            'status' => User::STATUS_BANNED,
            'ban_reason' => mb_substr(trim($reason), 0, 500),
            'banned_at' => now(),
            'banned_until' => $days ? now()->addDays($days) : null,
            'banned_by' => $admin->getKey(),
        ])->save();

        $this->signOutEverywhere($user);

        return $user;
    }

    public function unban(User $user): User
    {
        $user->forceFill([
            'status' => User::STATUS_ACTIVE,
            'ban_reason' => null,
            'banned_at' => null,
            'banned_until' => null,
            'banned_by' => null,
        ])->save();

        return $user;
    }

    /** Lift a ban whose time is up; true when the account can be used again. */
    public function liftIfEnded(User $user): bool
    {
        if ($user->banHasEnded()) {
            $this->unban($user);

            return true;
        }

        return ! $user->isBanned();
    }

    /** Lift every temporary ban whose time is up (scheduled). */
    public function liftEnded(): int
    {
        $count = 0;
        User::query()->where('status', User::STATUS_BANNED)->whereNotNull('banned_until')->where('banned_until', '<=', now())
            ->each(function (User $user) use (&$count) {
                $this->unban($user);
                $count++;
            });

        return $count;
    }

    /**
     * What the ban screen shows.
     *
     * @return array{reason: ?string, until: ?string, permanent: bool, since: ?string}
     */
    public function details(User $user): array
    {
        return [
            'reason' => $user->ban_reason,
            'until' => $user->banned_until?->toIso8601String(),
            'permanent' => $user->banned_until === null,
            'since' => $user->banned_at?->toIso8601String(),
        ];
    }

    /** Remember the ban for the ban screen (the person is signed out by then). */
    public function remember(Request $request, User $user): void
    {
        $request->session()->put(self::SESSION_KEY, $this->details($user));
    }

    private function signOutEverywhere(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->getKey())->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->save();
        $user->deviceTokens()->delete();
        app(WebPushService::class)->forgetUser($user);
        $this->presence->markOffline($user);
    }
}

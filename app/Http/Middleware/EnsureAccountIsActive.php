<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\BanService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out users whose account has been deactivated, suspended or banned by an admin.
 * Banned people see the ban screen with the reason and when the ban ends.
 */
class EnsureAccountIsActive
{
    public function __construct(private readonly BanService $bans) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        // A temporary ban whose time is up is lifted on the next visit.
        if ($user?->isBanned()) {
            $this->bans->liftIfEnded($user);
        }

        if ($user && ! $user->isActive()) {
            $message = match ($user->status) {
                User::STATUS_BANNED => 'Your account has been banned.',
                User::STATUS_SUSPENDED => 'Your account has been suspended. Please contact support.',
                default => 'Your account is inactive. Please contact support.',
            };

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($user->isBanned()) {
                $this->bans->remember($request, $user);
            }

            if ($request->expectsJson()) {
                return response()->json(['message' => $message, 'banned' => $user->isBanned()], Response::HTTP_FORBIDDEN);
            }

            return $user->isBanned()
                ? redirect()->route('account.banned')
                : redirect()->route('login')->withErrors(['login' => $message]);
        }

        return $next($request);
    }
}

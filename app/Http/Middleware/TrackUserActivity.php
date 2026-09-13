<?php

namespace App\Http\Middleware;

use App\Services\PresenceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps "last seen" fresh for authenticated users (throttled writes).
 */
class TrackUserActivity
{
    public function __construct(private readonly PresenceService $presence) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();

        // Skip explicit offline beacons and inactive accounts.
        if ($user && $user->isActive() && ! $request->routeIs('presence.offline', 'logout')) {
            $this->presence->touch($user);
        }

        return $response;
    }
}

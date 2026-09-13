<?php

namespace App\Http\Middleware;

use App\Services\DeviceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the mobile app's background service with its device token
 * (Authorization: Bearer …). Stateless: no session, cookies or CSRF.
 */
class AuthenticateDevice
{
    public function __construct(private readonly DeviceService $devices) {}

    public function handle(Request $request, Closure $next): Response
    {
        $device = $this->devices->findByToken((string) $request->bearerToken());

        if (! $device || ! $device->user) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        if (! $device->user->isActive()) {
            // A suspended or deactivated account loses its devices.
            $device->delete();

            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        $request->attributes->set('device', $device);
        $request->setUserResolver(fn () => $device->user);

        $this->devices->touch($device);

        return $next($request);
    }
}

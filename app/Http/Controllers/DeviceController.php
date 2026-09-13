<?php

namespace App\Http\Controllers;

use App\Http\Requests\Chat\RegisterDeviceRequest;
use App\Services\DeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Called by the web app running inside the mobile app to switch on
 * background message notifications for this phone.
 */
class DeviceController extends Controller
{
    /** Session key remembering this phone's token, so logging out signs the phone out too. */
    public const SESSION_KEY = 'device_token_hash';

    public function __construct(private readonly DeviceService $devices) {}

    public function store(RegisterDeviceRequest $request): JsonResponse
    {
        $user = $request->user();

        // The same phone registering again replaces its previous token.
        $previous = $request->session()->get(self::SESSION_KEY);
        if (is_string($previous)) {
            $this->devices->revoke($user, $previous);
        }

        ['token' => $token, 'device' => $device] = $this->devices->issue(
            $user,
            $request->validated('platform'),
            $request->validated('app_version'),
        );

        $request->session()->put(self::SESSION_KEY, $device->token_hash);

        return response()->json($this->devices->connectionDetails($user, $token), 201);
    }

    public function destroy(Request $request): Response
    {
        $hash = $request->session()->pull(self::SESSION_KEY);

        if (is_string($hash)) {
            $this->devices->revoke($request->user(), $hash);
        }

        return response()->noContent();
    }
}

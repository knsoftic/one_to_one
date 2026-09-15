<?php

namespace App\Http\Controllers;

use App\Services\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * X3 — a browser allows (or stops) push notifications for the signed-in account.
 */
class WebPushController extends Controller
{
    public function __construct(private readonly WebPushService $webPush) {}

    public function store(Request $request): JsonResponse
    {
        abort_unless($this->webPush->available(), 503, 'Browser notifications are not available on this server.');

        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'url:https', 'max:1000'],
            'keys.p256dh' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{80,100}$/'],
            'keys.auth' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{16,30}$/'],
        ]);

        $this->webPush->subscribe($request->user(), $validated, $request->session()->getId(), $request->userAgent());

        return response()->json(['message' => 'Notifications are on for this browser.'], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate(['endpoint' => ['required', 'string', 'max:1000']]);
        $this->webPush->unsubscribe($request->user(), $validated['endpoint']);

        return response()->json(['message' => 'Notifications are off for this browser.']);
    }
}

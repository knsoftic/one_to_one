<?php

namespace App\Http\Controllers;

use App\Services\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * P9 — Active sessions (settings page and the app's Linked devices panel).
 */
class SessionController extends Controller
{
    public function __construct(private readonly SessionService $sessions) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->sessions->list($request->user(), $request)]);
    }

    public function destroy(Request $request, string $key): JsonResponse|RedirectResponse
    {
        $this->sessions->logout($request->user(), $key, $request);

        return $request->expectsJson()
            ? response()->json(['key' => $key, 'signed_out' => true])
            : redirect()->route('profile.edit', ['tab' => 'security'])->with('status', 'That device has been signed out.');
    }

    public function destroyOthers(Request $request): JsonResponse|RedirectResponse
    {
        $count = $this->sessions->logoutOthers($request->user(), $request);
        $message = $count === 1 ? '1 other device has been signed out.' : "{$count} other devices have been signed out.";

        return $request->expectsJson()
            ? response()->json(['signed_out' => $count, 'message' => $message])
            : redirect()->route('profile.edit', ['tab' => 'security'])->with('status', $message);
    }
}

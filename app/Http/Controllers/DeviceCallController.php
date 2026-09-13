<?php

namespace App\Http\Controllers;

use App\Models\Call;
use App\Services\CallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Buttons on the phone's incoming-call screen and ongoing-call notification,
 * usable while the app is closed (authenticated with the device token).
 */
class DeviceCallController extends Controller
{
    public function __construct(private readonly CallService $calls) {}

    /** The phone is ringing ("Ringing…" for the caller). */
    public function ringing(Request $request, Call $call): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $call);

        return $this->state($this->calls->markRinging($call, $request->user()));
    }

    public function decline(Request $request, Call $call): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $call);

        if ($call->isCaller($request->user())) {
            return $this->state($call);
        }

        return $this->state($this->calls->decline($call, $request->user()));
    }

    public function end(Request $request, Call $call): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $call);

        return $this->state($this->calls->hangUp($call, $request->user()));
    }

    private function state(Call $call): JsonResponse
    {
        return response()->json(['id' => $call->id, 'status' => $call->status, 'end_reason' => $call->end_reason]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Services\MessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Live location: new positions from the sharing device, and "Stop sharing".
 */
class LiveLocationController extends Controller
{
    public function __construct(private readonly MessageService $messages) {}

    public function update(Request $request, Message $message): JsonResponse
    {
        Gate::authorize('updateLocation', $message);

        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        $message = $this->messages->updateLiveLocation($message, $validated);

        return response()->json(['id' => $message->id, 'location' => (new MessageResource($message))->resolve($request)['location']]);
    }

    public function destroy(Request $request, Message $message): JsonResponse
    {
        Gate::authorize('updateLocation', $message);

        $message = $this->messages->stopLiveLocation($message);

        return response()->json(['id' => $message->id, 'location' => (new MessageResource($message))->resolve($request)['location']]);
    }
}

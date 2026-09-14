<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\DeviceToken;
use App\Services\DeviceService;
use App\Services\MessageService;
use App\Services\PushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Endpoints used by the mobile app outside the WebView: the background
 * notification connection, the Firebase push token and the actions on a
 * message notification (authenticated with a device token, see AuthenticateDevice).
 */
class DeviceApiController extends Controller
{
    public function __construct(
        private readonly DeviceService $devices,
        private readonly MessageService $messages,
        private readonly PushService $push,
    ) {}

    /**
     * Sign a subscription to the user's own private Reverb channel.
     */
    public function broadcastingAuth(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'socket_id' => ['required', 'string', 'max:64', 'regex:/^\d+\.\d+$/'],
            'channel_name' => ['required', 'string', 'max:100'],
        ]);

        $auth = $this->devices->authorizeChannel($request->user(), $validated['socket_id'], $validated['channel_name']);

        abort_if($auth === null, Response::HTTP_FORBIDDEN, 'This channel is not available.');

        return response()->json(['auth' => $auth]);
    }

    /**
     * New unread message notifications since the last check (polling fallback).
     */
    public function notifications(Request $request): JsonResponse
    {
        $request->validate(['after' => ['nullable', 'string', 'max:40']]);

        $after = null;
        if ($request->filled('after')) {
            try {
                $after = Carbon::parse($request->string('after'));
            } catch (Throwable) {
                $after = null;
            }
        }

        return response()->json($this->devices->feed($request->user(), $after));
    }

    /**
     * Save (or remove, with an empty token) the phone's Firebase push token.
     */
    public function pushToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['nullable', 'string', 'min:20', 'max:4096', 'regex:/^[\x21-\x7E]+$/'],
        ]);

        /** @var DeviceToken $device */
        $device = $request->attributes->get('device');

        if (empty($validated['token'])) {
            $this->push->clearDeviceToken($device);
        } else {
            $this->push->setDeviceToken($device, $validated['token']);
        }

        return response()->json(['push_enabled' => $this->push->enabled() && ! empty($validated['token'])]);
    }

    /**
     * Messages reached the phone (✓✓ for the sender), even while the app is closed.
     */
    public function delivered(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'min:1'],
        ]);

        return response()->json(['updated' => $this->messages->markDelivered($request->user(), $validated['ids'])]);
    }

    /**
     * "Reply" typed in the notification: send a text message and mark the chat as read.
     */
    public function reply(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('sendMessage', $conversation);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:'.config('chat.max_message_length')],
        ]);

        $text = $this->messages->cleanText($validated['message']);
        abort_if(trim($text) === '', Response::HTTP_UNPROCESSABLE_ENTITY, 'The message is empty.');

        $this->messages->markSeen($conversation, $user);
        $message = $this->messages->sendText($user, $conversation, ['message' => $text]);

        return response()->json(['id' => $message->id, 'conversation_id' => $conversation->id], 201);
    }

    /**
     * "Mark as read" on the notification.
     */
    public function read(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('participate', $conversation);

        return response()->json(['ids' => $this->messages->markSeen($conversation, $user)]);
    }

    /**
     * Sign this phone out of notifications.
     */
    public function revoke(Request $request): Response
    {
        /** @var DeviceToken $device */
        $device = $request->attributes->get('device');
        $device->delete();

        return response()->noContent();
    }
}

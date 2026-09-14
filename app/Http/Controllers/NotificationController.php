<?php

namespace App\Http\Controllers;

use App\Models\ChatSetting;
use App\Services\ChatLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * In-app notification centre (database notifications).
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Chats in "Locked chats" (C9) stay hidden until the secret code is entered.
        $locked = app(ChatLockService::class)->isUnlocked()
            ? []
            : ChatSetting::query()->where('user_id', $user->getKey())->whereNotNull('locked_at')->pluck('conversation_id')->map(fn ($id) => (int) $id)->all();

        $notifications = $user->notifications()
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (DatabaseNotification $notification) => [
                'id' => $notification->id,
                'data' => in_array((int) ($notification->data['conversation_id'] ?? 0), $locked, true)
                    ? ['type' => $notification->data['type'] ?? 'new_message', 'title' => 'New message', 'body' => '', 'conversation_id' => (int) $notification->data['conversation_id'], 'locked' => true,
                        'sender' => ['id' => 0, 'name' => config('app.name'), 'display_name' => config('app.name'), 'username' => null, 'avatar_url' => null, 'initials' => '', 'avatar_hue' => 0]]
                    : $notification->data,
                'read' => $notification->read_at !== null,
                'created_at' => $notification->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'data' => $notifications,
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * Mark all notifications (or those of one conversation) as read.
     */
    public function markRead(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = $request->user()->unreadNotifications();

        if (isset($validated['conversation_id'])) {
            $query->where('data->conversation_id', (int) $validated['conversation_id']);
        }

        $query->update(['read_at' => now()]);

        return response()->json(['unread_count' => $request->user()->unreadNotifications()->count()]);
    }
}

<?php

namespace App\View\Composers;

use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * Configuration for the chat frontend: endpoint templates, limits and
 * realtime connection details. `__ID__` placeholders are replaced client-side.
 */
class ChatConfigComposer
{
    public function compose(View $view): void
    {
        $request = request();
        $user = $request->user();
        $id = '__ID__';

        $routes = [
            'chat' => route('chat.index'),
            'chatShow' => $this->template('chat.show', ['conversation' => $id]),
            'conversations' => route('conversations.index'),
            'conversationsStore' => route('conversations.store'),
            'conversationShow' => $this->template('conversations.show', ['conversation' => $id]),
            'messages' => $this->template('messages.index', ['conversation' => $id]),
            'messagesStore' => $this->template('messages.store', ['conversation' => $id]),
            'search' => route('users.search'),
            'online' => route('users.online'),
            'settings' => route('profile.edit'),
            'preferences' => route('profile.preferences'),
        ];

        // Endpoints added by later phases are included when registered.
        $optional = [
            'seen' => ['conversations.seen', ['conversation' => $id]],
            'typing' => ['conversations.typing', ['conversation' => $id]],
            'delivered' => ['messages.delivered', []],
            'messageUpdate' => ['messages.update', ['message' => $id]],
            'messageDestroy' => ['messages.destroy', ['message' => $id]],
            'heartbeat' => ['presence.heartbeat', []],
            'offline' => ['presence.offline', []],
            'sync' => ['chat.sync', []],
            'block' => ['blocks.store', ['user' => $id]],
            'unblock' => ['blocks.destroy', ['user' => $id]],
            'notifications' => ['notifications.index', []],
            'notificationsRead' => ['notifications.read', []],
        ];

        foreach ($optional as $key => [$name, $params]) {
            if (Route::has($name)) {
                $routes[$key] = $this->template($name, $params);
            }
        }

        $reverb = config('broadcasting.connections.reverb');

        $view->with('chatConfig', [
            'user' => (new UserResource($user))->resolve($request),
            'initialConversationId' => $view->getData()['initialConversationId'] ?? null,
            'routes' => $routes,
            'limits' => [
                'messageLength' => config('chat.max_message_length'),
                'perPage' => config('chat.messages_per_page'),
                'image' => config('chat.uploads.image'),
                'document' => config('chat.uploads.document'),
                'voice' => [
                    'max_kb' => config('chat.uploads.voice.max_kb'),
                    'max_seconds' => config('chat.uploads.voice.max_seconds'),
                ],
                'editWindowMinutes' => config('chat.edit_window_minutes'),
                'deleteWindowMinutes' => config('chat.delete_for_everyone_window_minutes'),
            ],
            'presence' => [
                'onlineThresholdSeconds' => config('chat.online_threshold_seconds'),
                'heartbeatSeconds' => config('chat.heartbeat_interval_seconds'),
                'typingTtlSeconds' => config('chat.typing_ttl_seconds'),
            ],
            'realtime' => [
                'enabled' => config('broadcasting.default') === 'reverb' && ! empty($reverb['key']),
                'key' => $reverb['key'] ?? null,
                'host' => $reverb['options']['host'] ?? null,
                'port' => (int) ($reverb['options']['port'] ?? 443),
                'scheme' => $reverb['options']['scheme'] ?? 'https',
                'pollingIntervalMs' => config('chat.polling_interval_ms'),
            ],
        ]);
    }

    private function template(string $name, array $params): string
    {
        return str_replace(['__ID__', '%5F%5FID%5F%5F'], '__ID__', route($name, $params));
    }
}

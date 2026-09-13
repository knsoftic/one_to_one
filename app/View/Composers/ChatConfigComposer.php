<?php

namespace App\View\Composers;

use App\Http\Resources\UserResource;
use App\Services\GifService;
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
            'messageForward' => ['messages.forward', ['message' => $id]],
            'messagesSearch' => ['messages.search', ['conversation' => $id]],
            'messageStar' => ['messages.star', ['message' => $id]],
            'messagePin' => ['messages.pin', ['message' => $id]],
            'starred' => ['starred.index', []],
            'linkPreview' => ['link-previews.show', []],
            'messageLocation' => ['messages.location.update', ['message' => $id]],
            'messageVote' => ['messages.vote', ['message' => $id]],
            'disappearing' => ['conversations.disappearing', ['conversation' => $id]],
            'viewOnce' => ['messages.view-once', ['message' => $id]],
            'stickers' => ['stickers.index', []],
            'stickersStore' => ['stickers.store', []],
            'stickerDestroy' => ['stickers.destroy', ['sticker' => $id]],
            'messageSaveSticker' => ['messages.sticker.save', ['message' => $id]],
            'gifs' => ['gifs.index', []],
            'messageReaction' => ['messages.reaction.update', ['message' => $id]],
            'heartbeat' => ['presence.heartbeat', []],
            'offline' => ['presence.offline', []],
            'sync' => ['chat.sync', []],
            'block' => ['blocks.store', ['user' => $id]],
            'unblock' => ['blocks.destroy', ['user' => $id]],
            'notifications' => ['notifications.index', []],
            'contacts' => ['contacts.index', []],
            'contactsSync' => ['contacts.sync', []],
            'contactDestroy' => ['contacts.destroy', ['contact' => $id]],
            'notificationsRead' => ['notifications.read', []],
            'callsStore' => ['calls.store', ['conversation' => $id]],
            'callsActive' => ['calls.active', []],
            'callShow' => ['calls.show', ['call' => $id]],
            'callRinging' => ['calls.ringing', ['call' => $id]],
            'callAccept' => ['calls.accept', ['call' => $id]],
            'callDecline' => ['calls.decline', ['call' => $id]],
            'callEnd' => ['calls.end', ['call' => $id]],
            'callHeartbeat' => ['calls.heartbeat', ['call' => $id]],
            'callSignalsStore' => ['calls.signals.store', ['call' => $id]],
            'callSignals' => ['calls.signals', ['call' => $id]],
        ];

        foreach ($optional as $key => [$name, $params]) {
            if (Route::has($name)) {
                $routes[$key] = $this->template($name, $params);
            }
        }

        // GIF search is offered only when a Tenor key is configured.
        if (! app(GifService::class)->enabled()) {
            unset($routes['gifs']);
        }

        $reverb = config('broadcasting.connections.reverb');

        $view->with('chatConfig', [
            'appName' => config('app.name'),
            'user' => (new UserResource($user))->resolve($request),
            'initialConversationId' => $view->getData()['initialConversationId'] ?? null,
            'routes' => $routes,
            'limits' => [
                'messageLength' => config('chat.max_message_length'),
                'perPage' => config('chat.messages_per_page'),
                'image' => config('chat.uploads.image'),
                'document' => config('chat.uploads.document'),
                'video' => [
                    'extensions' => config('chat.uploads.video.extensions'),
                    'max_kb' => config('chat.uploads.video.max_kb'),
                ],
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
            'calls' => [
                'enabled' => (bool) config('chat.calls.enabled', true) && Route::has('calls.store'),
                'ringTimeoutSeconds' => (int) config('chat.calls.ring_timeout_seconds', 45),
                'heartbeatSeconds' => (int) config('chat.calls.heartbeat_seconds', 20),
            ],
        ]);
    }

    private function template(string $name, array $params): string
    {
        return str_replace(['__ID__', '%5F%5FID%5F%5F'], '__ID__', route($name, $params));
    }
}

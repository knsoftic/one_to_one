<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\Message;
use App\Models\User;
use App\Notifications\NewMessageNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Mobile app devices: issues access tokens for the app's background
 * notification service, authorizes its WebSocket channel and serves the
 * polling feed of new-message notifications.
 */
class DeviceService
{
    private const FEED_LIMIT = 50;

    /**
     * Issue a new token for the phone. The plain token is returned only here.
     *
     * @return array{token: string, device: DeviceToken}
     */
    public function issue(User $user, string $platform, ?string $appVersion = null): array
    {
        $token = Str::random(64);

        $device = $user->deviceTokens()->create([
            'token_hash' => DeviceToken::hashToken($token),
            'platform' => $platform,
            'app_version' => $appVersion,
            'last_used_at' => now(),
        ]);

        return ['token' => $token, 'device' => $device];
    }

    public function findByToken(string $token): ?DeviceToken
    {
        if ($token === '' || strlen($token) > 255) {
            return null;
        }

        return DeviceToken::with('user')->where('token_hash', DeviceToken::hashToken($token))->first();
    }

    public function revoke(User $user, string $tokenHash): void
    {
        $user->deviceTokens()->where('token_hash', $tokenHash)->delete();
    }

    public function revokeAll(User $user): void
    {
        $user->deviceTokens()->delete();
    }

    public function touch(DeviceToken $device): void
    {
        // At most one write per minute per device.
        if (! $device->last_used_at || $device->last_used_at->lt(now()->subMinute())) {
            $device->forceFill(['last_used_at' => now()])->saveQuietly();
        }
    }

    /**
     * Private channel the app subscribes to (same one the web app uses).
     */
    public function channelFor(User $user): string
    {
        return 'private-App.Models.User.'.$user->getKey();
    }

    /**
     * WebSocket (Pusher protocol) address of Reverb, or null when realtime is not configured.
     */
    public function websocketUrl(): ?string
    {
        $reverb = config('broadcasting.connections.reverb');

        if (config('broadcasting.default') !== 'reverb' || empty($reverb['key']) || empty($reverb['options']['host'])) {
            return null;
        }

        $scheme = ($reverb['options']['scheme'] ?? 'https') === 'https' ? 'wss' : 'ws';
        $port = (int) ($reverb['options']['port'] ?? 443);

        return sprintf(
            '%s://%s:%d/app/%s?protocol=7&client=one2one-android&version=1.0&flash=false',
            $scheme,
            $reverb['options']['host'],
            $port,
            rawurlencode((string) $reverb['key']),
        );
    }

    /**
     * Pusher-protocol signature for the device's own private channel.
     */
    public function authorizeChannel(User $user, string $socketId, string $channel): ?string
    {
        $key = (string) config('broadcasting.connections.reverb.key');
        $secret = (string) config('broadcasting.connections.reverb.secret');

        if ($key === '' || $secret === '' || ! hash_equals($this->channelFor($user), $channel)) {
            return null;
        }

        return $key.':'.hash_hmac('sha256', $socketId.':'.$channel, $secret);
    }

    /**
     * Everything the app needs to start its background service.
     *
     * @return array<string, mixed>
     */
    public function connectionDetails(User $user, string $token): array
    {
        $details = $this->sharedDetails();

        return [
            'token' => $token,
            'user_id' => $user->getKey(),
            'server_url' => rtrim((string) config('app.url'), '/'),
            'server_time' => now()->toIso8601String(),
            'channel' => $this->channelFor($user),
            'config_version' => $this->configVersion(),
        ] + $details;
    }

    /**
     * Fingerprint of the connection details every phone shares. When it changes
     * (Firebase switched on, WebSocket address fixed, new endpoints…) the app
     * registers again, so phones never stay on outdated settings.
     */
    public function configVersion(): string
    {
        return substr(hash('sha256', (string) json_encode($this->sharedDetails())), 0, 16);
    }

    /**
     * @return array<string, mixed>
     */
    private function sharedDetails(): array
    {
        return [
            'websocket_url' => $this->websocketUrl(),
            'poll_interval_seconds' => max(15, (int) config('chat.mobile.poll_interval_seconds', 60)),
            'show_preview' => (bool) config('chat.mobile.show_preview', true),
            // Firebase push is used when the server is configured and the phone supports it.
            'push' => ['fcm' => app(PushService::class)->enabled()],
            'endpoints' => [
                'auth' => route('device.broadcasting.auth'),
                'notifications' => route('device.notifications'),
                'revoke' => route('device.revoke'),
                'push_token' => route('device.push-token'),
                'delivered' => route('device.delivered'),
                // __ID__ is replaced by the conversation id on the phone.
                'reply' => route('device.reply', ['conversation' => '__ID__']),
                'read' => route('device.read', ['conversation' => '__ID__']),
                // __ID__ is replaced by the call id.
                'call_ringing' => route('device.calls.ringing', ['call' => '__ID__']),
                'call_decline' => route('device.calls.decline', ['call' => '__ID__']),
                'call_end' => route('device.calls.end', ['call' => '__ID__']),
            ],
        ];
    }

    /**
     * Unread new-message notifications created at or after $after, for polling.
     *
     * @return array{data: list<array<string, mixed>>, unread_conversation_ids: list<int>, server_time: string}
     */
    public function feed(User $user, ?Carbon $after): array
    {
        $serverTime = now()->toIso8601String();

        $unreadConversationIds = Message::query()
            ->unreadFor($user)
            ->distinct()
            ->pluck('conversation_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if (! $user->notifications_enabled || $unreadConversationIds === []) {
            return ['data' => [], 'unread_conversation_ids' => $unreadConversationIds, 'server_time' => $serverTime];
        }

        $notifications = $user->unreadNotifications()
            ->where('type', NewMessageNotification::class)
            ->when($after, fn ($query) => $query->where('created_at', '>=', $after))
            ->latest()
            ->limit(self::FEED_LIMIT)
            ->get();

        $data = $notifications
            ->filter(fn ($notification) => in_array((int) ($notification->data['conversation_id'] ?? 0), $unreadConversationIds, true))
            // Oldest first; messages sent within the same second keep their order.
            ->sortBy(fn ($notification) => [$notification->created_at?->getTimestamp() ?? 0, (int) ($notification->data['message_id'] ?? 0)])
            ->map(fn ($notification) => $this->feedItem($notification->id, $notification->data, $notification->created_at))
            ->values()
            ->all();

        return ['data' => $data, 'unread_conversation_ids' => $unreadConversationIds, 'server_time' => $serverTime];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function feedItem(string $id, array $data, ?Carbon $createdAt): array
    {
        return [
            'id' => $id,
            'type' => 'message.notification',
            'conversation_id' => (int) ($data['conversation_id'] ?? 0),
            'message_id' => (int) ($data['message_id'] ?? 0),
            'body' => $data['body'] ?? '',
            'sender' => $data['sender'] ?? [],
            'created_at' => $createdAt?->toIso8601String(),
            // D4: sound and vibration chosen when the message arrived.
            'tone' => $data['tone'] ?? 'default',
            'vibrate' => $data['vibrate'] ?? 'default',
        ];
    }
}

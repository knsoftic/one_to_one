<?php

namespace App\Services;

use App\Http\Resources\CallResource;
use App\Http\Resources\MessageResource;
use App\Models\Call;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Incremental state for the AJAX polling fallback (used when the WebSocket
 * connection is unavailable) and for catching up after a reconnect.
 */
class SyncService
{
    private const MESSAGE_LIMIT = 200;

    public function __construct(private readonly TypingService $typing) {}

    /**
     * @return array<string, mixed>
     */
    public function since(Request $request, User $user, Carbon $since, ?int $conversationId = null): array
    {
        // Small overlap so rows committed during the previous request are not missed.
        $serverTime = now()->subSeconds(2);

        $messages = $this->changedMessages($user, $since);
        $truncated = $messages->count() >= self::MESSAGE_LIMIT;

        return [
            'server_time' => $serverTime->toIso8601String(),
            'truncated' => $truncated,
            'messages' => $messages->map(fn (Message $message) => $message->isDeletedFor($user)
                ? ['id' => $message->id, 'conversation_id' => $message->conversation_id, 'hidden' => true]
                : (new MessageResource($message))->resolve($request)
            )->values(),
            'typing' => $this->typingState($user, $conversationId),
            'presence' => $this->contactPresence($user),
            // Ringing and ongoing calls (incoming calls still ring while polling).
            'calls' => CallResource::collection(
                Call::query()->active()->involving($user)->with(['caller', 'callee'])->latest('id')->limit(5)->get()
            )->resolve($request),
        ];
    }

    private function changedMessages(User $user, Carbon $since)
    {
        $query = fn (string $column) => Message::query()
            ->where($column, $user->getKey())
            ->where('updated_at', '>=', $since)
            ->with('replyTo')
            ->orderBy('updated_at')
            ->limit(self::MESSAGE_LIMIT)
            ->get();

        return $query('sender_id')
            ->concat($query('receiver_id'))
            ->unique('id')
            ->sortBy('id')
            ->take(self::MESSAGE_LIMIT);
    }

    /**
     * @return array{conversation_id:int, typing:bool}|null
     */
    private function typingState(User $user, ?int $conversationId): ?array
    {
        if (! $conversationId) {
            return null;
        }

        $conversation = Conversation::query()->forUser($user)->find($conversationId);

        return $conversation ? [
            'conversation_id' => $conversation->id,
            'user_id' => $conversation->otherParticipantId($user),
            'typing' => $this->typing->isOtherTyping($conversation, $user),
        ] : null;
    }

    /**
     * Online state of the people the user has conversations with.
     */
    private function contactPresence(User $user): array
    {
        $contactIds = Conversation::query()
            ->forUser($user)
            ->latest('updated_at')
            ->limit(200)
            ->get(['user_one_id', 'user_two_id'])
            ->map(fn (Conversation $c) => $c->otherParticipantId($user))
            ->unique();

        return User::query()
            ->whereKey($contactIds)
            ->get(['id', 'is_online', 'last_seen'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'is_online' => $u->isOnlineNow(),
                'last_seen' => $u->last_seen?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}

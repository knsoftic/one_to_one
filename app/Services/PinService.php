<?php

namespace App\Services;

use App\Events\ConversationPinsUpdated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PinnedMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pinned messages: shared by both participants, up to three per chat, each for 24 hours,
 * 7 days or 30 days.
 */
class PinService
{
    public function pin(Message $message, User $user, int $durationSeconds): PinnedMessage
    {
        $pin = DB::transaction(function () use ($message, $user, $durationSeconds) {
            $conversationId = $message->conversation_id;

            PinnedMessage::query()->where('conversation_id', $conversationId)->where('expires_at', '<=', now())->delete();

            $pin = PinnedMessage::query()->updateOrCreate(
                ['conversation_id' => $conversationId, 'message_id' => $message->getKey()],
                ['pinned_by' => $user->getKey(), 'expires_at' => now()->addSeconds($durationSeconds), 'created_at' => now()],
            );

            // Keep the newest three.
            $keep = PinnedMessage::query()->where('conversation_id', $conversationId)
                ->orderByDesc('created_at')->orderByDesc('id')
                ->limit(PinnedMessage::MAX_PER_CONVERSATION)
                ->pluck('id');
            PinnedMessage::query()->where('conversation_id', $conversationId)->whereNotIn('id', $keep)->delete();

            return $pin;
        });

        $this->broadcast($message->conversation_id);

        return $pin;
    }

    public function unpin(Message $message): void
    {
        $deleted = PinnedMessage::query()->where('message_id', $message->getKey())->delete();

        if ($deleted) {
            $this->broadcast($message->conversation_id);
        }
    }

    /**
     * Active pins the user can see, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function visibleFor(Conversation $conversation, User $user): array
    {
        return PinnedMessage::query()
            ->where('conversation_id', $conversation->getKey())
            ->where('expires_at', '>', now())
            ->whereHas('message', fn ($q) => $q->visibleTo($user)->where('deleted_for_everyone', false))
            ->with('message')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PinnedMessage $pin) => [
                'message_id' => $pin->message_id,
                'sender_id' => $pin->message->sender_id,
                'type' => $pin->message->message_type,
                'preview' => $pin->message->preview(120),
                'pinned_by_me' => (int) $pin->pinned_by === (int) $user->getKey(),
                'expires_at' => $pin->expires_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function broadcast(int $conversationId): void
    {
        $conversation = Conversation::query()->find($conversationId);

        if ($conversation) {
            broadcast(new ConversationPinsUpdated($conversation))->toOthers();
        }
    }
}

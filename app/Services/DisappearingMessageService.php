<?php

namespace App\Services;

use App\Events\MessagesExpired;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * M21 — Disappearing messages: a per-chat timer (24 hours, 7 days or 90 days)
 * for new messages; expired messages are removed for both people.
 */
class DisappearingMessageService
{
    /** Seconds allowed for the timer (null = off). */
    public const DURATIONS = [86400, 604800, 7776000];

    public function __construct(
        private readonly MessageService $messages,
        private readonly AttachmentService $attachments,
    ) {}

    /**
     * Change the timer and leave a notice in the chat for both people.
     */
    public function set(Conversation $conversation, User $user, ?int $seconds): Message
    {
        $seconds = $seconds ?: null;

        $conversation->forceFill(['disappearing_seconds' => $seconds])->save();

        return $this->messages->systemNotice($user, $conversation, [
            'event' => 'disappearing',
            'seconds' => $seconds,
        ]);
    }

    /**
     * Remove every message whose time is up. Returns how many were removed.
     */
    public function expire(): int
    {
        $removed = 0;

        Message::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(200, function ($messages) use (&$removed) {
                foreach ($messages->groupBy('conversation_id') as $conversationId => $group) {
                    foreach ($group as $message) {
                        $this->attachments->delete($message);
                    }

                    // Reactions, stars, pins and poll votes go with the rows (foreign keys cascade).
                    DB::transaction(function () use ($group, $conversationId) {
                        Message::query()->whereKey($group->modelKeys())->delete();

                        $conversation = Conversation::find($conversationId);
                        $conversation?->forceFill(['last_message_id' => $conversation->messages()->max('id')])->save();
                    });

                    $first = $group->first();
                    broadcast(new MessagesExpired(
                        (int) $conversationId,
                        $first->audienceIds(),
                        $group->modelKeys(),
                    ));

                    $removed += $group->count();
                }
            });

        return $removed;
    }
}

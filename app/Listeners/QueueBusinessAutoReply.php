<?php

namespace App\Listeners;

use App\Events\MessageSent;
use App\Jobs\SendBusinessAutoReply;
use App\Models\BusinessProfile;
use App\Models\Message;

/**
 * X8 — a message to a business with an away message or greeting turned on.
 */
class QueueBusinessAutoReply
{
    public function handle(MessageSent $event): void
    {
        $message = $event->message;

        if ($message->receiver_id === null || ! empty($message->attachment_meta['auto_reply'])
            || in_array($message->message_type, [Message::TYPE_SYSTEM, Message::TYPE_CALL], true)) {
            return;
        }

        $hasAutoReplies = BusinessProfile::query()->where('user_id', $message->receiver_id)
            ->where(fn ($q) => $q->where('away_enabled', true)->orWhere('greeting_enabled', true))
            ->exists();

        if ($hasAutoReplies) {
            SendBusinessAutoReply::dispatchAfterResponse($message->getKey());
        }
    }
}

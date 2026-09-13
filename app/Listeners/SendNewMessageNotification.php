<?php

namespace App\Listeners;

use App\Events\MessageSent;
use App\Notifications\NewMessageNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Notify the receiver when a new message arrives.
 */
class SendNewMessageNotification
{
    public function handle(MessageSent $event): void
    {
        $message = $event->message->loadMissing(['sender', 'receiver']);
        $receiver = $message->receiver;

        if (! $receiver || ! $receiver->isActive()) {
            return;
        }

        try {
            $receiver->notify(new NewMessageNotification($message));
        } catch (Throwable $e) {
            // A notification failure must never undo a sent message.
            Log::warning('New message notification failed: '.$e->getMessage(), ['message_id' => $message->id]);
        }
    }
}

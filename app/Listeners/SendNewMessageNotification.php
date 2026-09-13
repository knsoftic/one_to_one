<?php

namespace App\Listeners;

use App\Events\MessageSent;
use App\Jobs\SendMessagePush;
use App\Models\Call;
use App\Models\Message;
use App\Notifications\NewMessageNotification;
use App\Services\PushService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Notify the receiver when a new message arrives: notification centre,
 * realtime broadcast and a push to their phones.
 */
class SendNewMessageNotification
{
    public function __construct(private readonly PushService $push) {}

    public function handle(MessageSent $event): void
    {
        $message = $event->message->loadMissing(['sender', 'receiver']);
        $receiver = $message->receiver;

        if (! $receiver || ! $receiver->isActive()) {
            return;
        }

        // Call history only notifies the person called, and only about missed calls.
        if ($message->message_type === Message::TYPE_CALL
            && ! in_array($message->attachment_meta['reason'] ?? null, Call::MISSED_REASONS, true)) {
            return;
        }

        // One id for the notification centre, the realtime event and the push,
        // so the phone never shows the same message twice.
        $notification = new NewMessageNotification($message);
        $notification->id = (string) Str::uuid();

        try {
            $receiver->notify($notification);
        } catch (Throwable $e) {
            // A notification failure must never undo a sent message.
            Log::warning('New message notification failed: '.$e->getMessage(), ['message_id' => $message->id]);
        }

        // Firebase push runs after the response is sent (no queue worker needed).
        if ($receiver->notifications_enabled && $this->push->enabled()) {
            SendMessagePush::dispatchAfterResponse($message->id, $notification->id);
        }
    }
}

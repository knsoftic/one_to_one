<?php

namespace App\Listeners;

use App\Events\MessageSent;
use App\Jobs\SendMessagePush;
use App\Models\Call;
use App\Models\ChatSetting;
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

        // Chat notices (e.g. disappearing messages turned on) and notes to self never notify.
        if ($message->message_type === Message::TYPE_SYSTEM || (int) $message->sender_id === (int) $message->receiver_id) {
            return;
        }

        $setting = ChatSetting::query()
            ->where('user_id', $receiver->getKey())
            ->where('conversation_id', $message->conversation_id)
            ->first(['muted_until', 'locked_at']);

        // Muted chats (C2) still count as unread, but make no notification or push.
        if ($setting?->isMuted()) {
            return;
        }

        // Call history only notifies the person called, and only about missed calls.
        if ($message->message_type === Message::TYPE_CALL
            && ! in_array($message->attachment_meta['reason'] ?? null, Call::MISSED_REASONS, true)) {
            return;
        }

        // One id for the notification centre, the realtime event and the push,
        // so the phone never shows the same message twice.
        // Locked chats (C9) notify without saying who wrote or what.
        $notification = new NewMessageNotification($message, private: $setting?->locked_at !== null);
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

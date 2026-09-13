<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\ContactService;
use App\Services\PushService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Push a new message to the receiver's phones. Dispatched after the HTTP
 * response is sent, so the sender never waits for Firebase.
 */
class SendMessagePush
{
    use Dispatchable;

    public function __construct(public int $messageId, public ?string $notificationId = null) {}

    public function handle(PushService $push, ContactService $contacts): void
    {
        $message = Message::with(['sender', 'receiver'])->find($this->messageId);
        $receiver = $message?->receiver;
        $sender = $message?->sender;

        if (! $message || ! $receiver || ! $sender || $message->deleted_for_everyone || $message->seen_at) {
            return;
        }

        if (! $receiver->isActive() || ! $receiver->notifications_enabled) {
            return;
        }

        // The name saved in the receiver's phone book, like WhatsApp.
        $name = $contacts->savedNames($receiver, [$sender->id])[$sender->id] ?? $sender->name;

        try {
            $push->sendToUser($receiver, [
                'type' => 'message',
                'id' => $this->notificationId ?? 'message-'.$message->id,
                'conversation_id' => $message->conversation_id,
                'message_id' => $message->id,
                'sender_id' => $sender->id,
                'sender_name' => $name,
                'avatar_url' => $sender->avatar_url,
                'initials' => $sender->initials,
                'avatar_hue' => $sender->avatar_hue,
                'body' => config('chat.mobile.show_preview', true) ? $message->preview(200) : '',
                'sent_at' => ($message->sent_at ?? $message->created_at)?->getTimestampMs(),
            ], PushService::PRIORITY_HIGH);
        } catch (Throwable $e) {
            Log::warning('Message push failed: '.$e->getMessage(), ['message_id' => $message->id]);
        }
    }
}

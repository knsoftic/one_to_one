<?php

namespace App\Jobs;

use App\Models\ChatSetting;
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

        // Locked chats (C9): the phone shows only that a message arrived.
        $locked = ChatSetting::query()
            ->where('user_id', $receiver->getKey())
            ->where('conversation_id', $message->conversation_id)
            ->whereNotNull('locked_at')
            ->exists();

        // The name saved in the receiver's phone book, like WhatsApp.
        $name = $locked ? (string) config('app.name') : ($contacts->savedNames($receiver, [$sender->id])[$sender->id] ?? $sender->name);

        try {
            $push->sendToUser($receiver, [
                'type' => 'message',
                'id' => $this->notificationId ?? 'message-'.$message->id,
                'conversation_id' => $message->conversation_id,
                'message_id' => $message->id,
                'sender_id' => $locked ? 0 : $sender->id,
                'sender_name' => $name,
                'avatar_url' => $locked ? null : $sender->avatar_url,
                'initials' => $locked ? '' : $sender->initials,
                'avatar_hue' => $locked ? 0 : $sender->avatar_hue,
                'body' => ! $locked && config('chat.mobile.show_preview', true) ? $message->preview(200) : '',
                'sent_at' => ($message->sent_at ?? $message->created_at)?->getTimestampMs(),
            ], PushService::PRIORITY_HIGH);
        } catch (Throwable $e) {
            Log::warning('Message push failed: '.$e->getMessage(), ['message_id' => $message->id]);
        }
    }
}

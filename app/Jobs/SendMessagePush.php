<?php

namespace App\Jobs;

use App\Models\ChatSetting;
use App\Models\Message;
use App\Models\User;
use App\Services\ContactService;
use App\Services\PrivacyService;
use App\Services\PushService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Push a new message to the receiver's phones. Dispatched after the HTTP
 * response is sent, so the sender never waits for Firebase.
 */
class SendMessagePush
{
    use Dispatchable;

    /**
     * @param  int|null  $receiverId  the group member to notify (group messages have no receiver)
     */
    public function __construct(public int $messageId, public ?string $notificationId = null, public ?int $receiverId = null) {}

    public function handle(PushService $push, ContactService $contacts): void
    {
        $message = Message::with(['sender', 'receiver', 'conversation'])->find($this->messageId);
        $group = $message?->isGroupMessage() ? $message->conversation : null;
        $receiver = $group ? User::find($this->receiverId) : $message?->receiver;
        $sender = $message?->sender;

        if (! $message || ! $receiver || ! $sender || $message->deleted_for_everyone) {
            return;
        }

        // Already read (in a group: by this member).
        $read = $group
            ? DB::table('message_receipts')->where('message_id', $message->id)->where('user_id', $receiver->getKey())->whereNotNull('seen_at')->exists()
            : $message->seen_at !== null;
        if ($read) {
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
        $body = ! $locked && config('chat.mobile.show_preview', true) ? $message->preview(200) : '';

        // Groups: the phone shows the group (name and icon) with "Sara: message".
        if ($group && ! $locked) {
            $body = $message->message_type === Message::TYPE_SYSTEM ? $body : ($body !== '' ? "{$name}: {$body}" : '');
        }

        try {
            $push->sendToUser($receiver, [
                'type' => 'message',
                'id' => $this->notificationId ?? 'message-'.$message->id,
                'conversation_id' => $message->conversation_id,
                'message_id' => $message->id,
                'sender_id' => $locked ? 0 : ($group ? -$group->id : $sender->id),
                'sender_name' => $group && ! $locked ? (string) $group->name : $name,
                'avatar_url' => $locked ? null : ($group ? $group->groupAvatarUrl() : (app(PrivacyService::class)->canSeePhoto($sender, $receiver) ? $sender->avatar_url : null)),
                'initials' => $locked ? '' : ($group ? $group->groupInitials() : $sender->initials),
                'avatar_hue' => $locked ? 0 : ($group ? $group->groupHue() : $sender->avatar_hue),
                'body' => $body,
                'sent_at' => ($message->sent_at ?? $message->created_at)?->getTimestampMs(),
            ], PushService::PRIORITY_HIGH);
        } catch (Throwable $e) {
            Log::warning('Message push failed: '.$e->getMessage(), ['message_id' => $message->id]);
        }
    }
}

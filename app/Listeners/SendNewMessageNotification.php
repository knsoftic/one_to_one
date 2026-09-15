<?php

namespace App\Listeners;

use App\Events\MessageSent;
use App\Jobs\SendMessagePush;
use App\Models\Call;
use App\Models\ChatSetting;
use App\Models\ConversationMember;
use App\Models\Message;
use App\Models\User;
use App\Notifications\NewMessageNotification;
use App\Services\PushService;
use App\Support\ChatPreferences;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Notify the receiver when a new message arrives: notification centre,
 * realtime broadcast and a push to their phones. In groups, everyone else in it.
 */
class SendNewMessageNotification
{
    public function __construct(private readonly PushService $push) {}

    public function handle(MessageSent $event): void
    {
        $message = $event->message;

        if ($message->isGroupMessage()) {
            $this->notifyGroup($message);

            return;
        }

        $message->loadMissing(['sender', 'receiver']);
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
            ->first(['muted_until', 'locked_at', 'notification_tone', 'notification_vibrate']);

        // Muted chats (C2) still count as unread, but make no notification or push.
        if ($setting?->isMuted()) {
            return;
        }

        // Call history only notifies the person called, and only about missed calls.
        if ($message->message_type === Message::TYPE_CALL
            && ! in_array($message->attachment_meta['reason'] ?? null, Call::MISSED_REASONS, true)) {
            return;
        }

        $this->deliver($message, $receiver, $setting?->locked_at !== null, alert: ChatPreferences::alertFor($receiver, $setting));
    }

    /**
     * Phase 4: everyone in the group except the sender; muted groups stay quiet unless
     * the person is mentioned (G4). Being added to a group notifies the people added.
     */
    private function notifyGroup(Message $message): void
    {
        $message->loadMissing(['sender', 'conversation']);
        $meta = $message->attachment_meta ?? [];

        // Channel updates (G11) show up in the chat list without a notification.
        if ($message->conversation?->isChannel()) {
            return;
        }

        if ($message->message_type === Message::TYPE_SYSTEM) {
            $added = in_array($meta['event'] ?? null, ['group_created', 'members_added'], true)
                ? collect($meta['users'] ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all()
                : [];

            if ($added !== []) {
                $this->each($message, User::query()->whereKey($added)->get(), ignoreMute: true);
            }

            return;
        }

        $recipientIds = ConversationMember::query()
            ->where('conversation_id', $message->conversation_id)
            ->whereNull('left_at')
            ->where('user_id', '!=', $message->sender_id)
            ->pluck('user_id');

        $this->each($message, User::query()->whereKey($recipientIds)->get());
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function each(Message $message, Collection $users, bool $ignoreMute = false): void
    {
        $settings = ChatSetting::query()
            ->where('conversation_id', $message->conversation_id)
            ->whereIn('user_id', $users->modelKeys())
            ->get(['user_id', 'muted_until', 'locked_at', 'notification_tone', 'notification_vibrate'])
            ->keyBy('user_id');

        $mentioned = collect($message->attachment_meta['mention_ids'] ?? [])->map(fn ($id) => (int) $id)->all();
        // A reply to someone's message reaches them even in a muted group, like a mention.
        if ($message->reply_to_id && ($repliedTo = Message::query()->whereKey($message->reply_to_id)->value('sender_id'))) {
            $mentioned[] = (int) $repliedTo;
        }

        foreach ($users as $user) {
            if (! $user->isActive()) {
                continue;
            }

            $setting = $settings->get($user->getKey());
            $isMentioned = in_array((int) $user->getKey(), $mentioned, true);

            if (! $ignoreMute && ! $isMentioned && $setting?->isMuted()) {
                continue;
            }

            $this->deliver($message, $user, $setting?->locked_at !== null, $isMentioned, ChatPreferences::alertFor($user, $setting));
        }
    }

    private function deliver(Message $message, User $receiver, bool $locked, bool $mentioned = false, ?array $alert = null): void
    {
        // One id for the notification centre, the realtime event and the push,
        // so the phone never shows the same message twice.
        // Locked chats (C9) notify without saying who wrote or what.
        $notification = new NewMessageNotification($message, private: $locked, mentioned: $mentioned, alert: $alert);
        $notification->id = (string) Str::uuid();

        try {
            $receiver->notify($notification);
        } catch (Throwable $e) {
            // A notification failure must never undo a sent message.
            Log::warning('New message notification failed: '.$e->getMessage(), ['message_id' => $message->id]);
        }

        // Firebase push runs after the response is sent (no queue worker needed).
        if ($receiver->notifications_enabled && $this->push->reachable($receiver)) {
            SendMessagePush::dispatchAfterResponse($message->id, $notification->id, (int) $receiver->getKey());
        }
    }
}

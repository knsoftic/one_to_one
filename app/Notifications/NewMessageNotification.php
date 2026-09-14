<?php

namespace App\Notifications;

use App\Models\Message;
use App\Models\User;
use App\Services\ContactService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * "Ahmed sent you a message" — stored in the notifications table (in-app
 * notification centre) and broadcast for toasts / desktop notifications.
 */
class NewMessageNotification extends Notification
{
    use Queueable;

    private ?string $displayName = null;

    /**
     * @param  bool  $private  the chat is locked (C9): no sender, no text
     */
    public function __construct(public Message $message, public bool $private = false) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->notifications_enabled ? ['database', 'broadcast'] : ['database'];
    }

    public function toArray(User $notifiable): array
    {
        if ($this->private) {
            return [
                'type' => 'new_message',
                'title' => 'New message',
                'body' => '',
                'conversation_id' => $this->message->conversation_id,
                'message_id' => $this->message->id,
                'message_type' => Message::TYPE_TEXT,
                'locked' => true,
                'sender' => ['id' => 0, 'name' => config('app.name'), 'display_name' => config('app.name'), 'username' => null, 'avatar_url' => null, 'initials' => '', 'avatar_hue' => 0],
            ];
        }

        $sender = $this->message->sender;

        return [
            'type' => 'new_message',
            'title' => $this->message->message_type === Message::TYPE_CALL
                ? ltrim(mb_substr($this->message->callPreview(), 2)).' from '.$sender->name
                : "{$sender->name} sent you a message",
            'body' => $this->message->preview(100),
            'conversation_id' => $this->message->conversation_id,
            'message_id' => $this->message->id,
            'message_type' => $this->message->message_type,
            'sender' => [
                'id' => $sender->id,
                'name' => $sender->name,
                // Name saved in the receiver's phone book (used by phone notifications).
                'display_name' => $this->displayNameFor($notifiable),
                'username' => $sender->username,
                'avatar_url' => $sender->avatar_url,
                'initials' => $sender->initials,
                'avatar_hue' => $sender->avatar_hue,
            ],
        ];
    }

    private function displayNameFor(User $notifiable): string
    {
        $sender = $this->message->sender;

        return $this->displayName ??= app(ContactService::class)->savedNames($notifiable, [$sender->id])[$sender->id] ?? $sender->name;
    }

    /**
     * Broadcast immediately (no queue worker required).
     */
    public function toBroadcast(User $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toArray($notifiable)))->onConnection('sync');
    }

    public function broadcastType(): string
    {
        return 'message.notification';
    }
}

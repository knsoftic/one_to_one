<?php

namespace App\Notifications;

use App\Models\Message;
use App\Models\User;
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

    public function __construct(public Message $message) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return $notifiable->notifications_enabled ? ['database', 'broadcast'] : ['database'];
    }

    public function toArray(User $notifiable): array
    {
        $sender = $this->message->sender;

        return [
            'type' => 'new_message',
            'title' => "{$sender->name} sent you a message",
            'body' => $this->message->preview(100),
            'conversation_id' => $this->message->conversation_id,
            'message_id' => $this->message->id,
            'message_type' => $this->message->message_type,
            'sender' => [
                'id' => $sender->id,
                'name' => $sender->name,
                'username' => $sender->username,
                'avatar_url' => $sender->avatar_url,
                'initials' => $sender->initials,
                'avatar_hue' => $sender->avatar_hue,
            ],
        ];
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

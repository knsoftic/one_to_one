<?php

namespace App\Notifications;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the old address when someone's email is changed, so a stolen session
 * can't quietly take the account over.
 */
class EmailChangedNotification extends Notification
{
    public function __construct(private readonly string $name, private readonly string $newEmail) {}

    /**
     * @return list<string>
     */
    public function via(AnonymousNotifiable $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(AnonymousNotifiable $notifiable): MailMessage
    {
        $masked = preg_replace('/(?<=.).(?=[^@]*@)/u', '•', $this->newEmail);

        return (new MailMessage)
            ->subject('Your '.config('app.name').' email was changed')
            ->greeting("Hi {$this->name},")
            ->line("The email of your account was changed to {$masked}.")
            ->line('If you did this, you can ignore this email. If you didn\'t, reset your password straight away and contact support.');
    }
}

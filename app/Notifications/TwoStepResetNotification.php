<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * "Forgot PIN?" — an emailed link that turns two-step verification off (P7).
 */
class TwoStepResetNotification extends Notification
{
    public const LINK_MINUTES = 60;

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute('two-step.reset', now()->addMinutes(self::LINK_MINUTES), ['user' => $notifiable->getKey()]);

        return (new MailMessage)
            ->subject('Turn off two-step verification')
            ->greeting("Hi {$notifiable->name},")
            ->line('Someone (hopefully you) asked to turn off two-step verification because the PIN was forgotten.')
            ->action('Turn off two-step verification', $url)
            ->line('The link works for '.self::LINK_MINUTES.' minutes. If you didn\'t ask for this, ignore this email — your PIN stays on.');
    }
}

<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * Coins, payments, promotions, plans and referrals (Y2): one notification type for all of them,
 * stored for the notification centre and broadcast for the toast. `url` opens the settings tab
 * the event belongs to.
 */
class MoneyNotification extends Notification
{
    use Queueable;

    public const TYPES = [
        'coins_credited', 'payment_approved', 'payment_rejected', 'payment_refunded',
        'promotion_submitted', 'promotion_approved', 'promotion_rejected', 'promotion_finished', 'promotion_stopped',
        'plan_activated', 'plan_ending', 'plan_expired', 'referral_rewarded', 'badge_activated',
    ];

    /**
     * @param  array{title: string, body?: string, url?: string, tab?: string}  $data
     */
    public function __construct(public string $type, public array $data) {}

    /** @return list<string> */
    public function via(User $notifiable): array
    {
        return $notifiable->notifications_enabled ? ['database', 'broadcast'] : ['database'];
    }

    public function toArray(User $notifiable): array
    {
        return $this->payload();
    }

    public function toBroadcast(User $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->payload());
    }

    public function broadcastType(): string
    {
        return 'money';
    }

    private function payload(): array
    {
        return [
            'kind' => 'money',
            'type' => $this->type,
            'title' => (string) ($this->data['title'] ?? ''),
            'body' => (string) ($this->data['body'] ?? ''),
            'url' => $this->data['url'] ?? route('profile.edit', ['tab' => $this->data['tab'] ?? 'wallet']),
            'icon' => str_starts_with($this->type, 'plan_') || $this->type === 'badge_activated' ? 'crown' : 'coins',
        ];
    }
}

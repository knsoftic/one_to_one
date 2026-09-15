<?php

namespace App\Services;

use App\Models\BusinessProfile;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * X8 — business profile, and the automatic away and greeting messages.
 */
class BusinessService
{
    public function __construct(private readonly MessageService $messages) {}

    public function profileFor(User|int $user): ?BusinessProfile
    {
        return BusinessProfile::query()->where('user_id', $user instanceof User ? $user->getKey() : $user)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveProfile(User $user, array $data): BusinessProfile
    {
        $days = [];
        foreach (array_keys(BusinessProfile::DAYS) as $key) {
            $day = $data['days'][$key] ?? [];
            $days[$key] = [
                'open' => filter_var($day['open'] ?? false, FILTER_VALIDATE_BOOL),
                'from' => $day['from'] ?? '09:00',
                'to' => $day['to'] ?? '17:00',
            ];
        }

        return BusinessProfile::query()->updateOrCreate(['user_id' => $user->getKey()], [
            'category' => $data['category'],
            'description' => $this->clean($data['description'] ?? null),
            'address' => $this->clean($data['address'] ?? null),
            'email' => $this->clean($data['email'] ?? null),
            'website' => $this->clean($data['website'] ?? null),
            'hours' => ['mode' => $data['hours_mode'] ?? 'custom', 'days' => $days],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveMessages(BusinessProfile $profile, array $data): BusinessProfile
    {
        $profile->forceFill([
            'away_enabled' => filter_var($data['away_enabled'] ?? false, FILTER_VALIDATE_BOOL),
            'away_message' => $this->clean($data['away_message'] ?? null),
            'away_schedule' => $data['away_schedule'] ?? 'always',
            'away_from' => ($data['away_schedule'] ?? null) === 'custom' ? ($data['away_from'] ?? null) : null,
            'away_until' => ($data['away_schedule'] ?? null) === 'custom' ? ($data['away_until'] ?? null) : null,
            'away_recipients' => $data['away_recipients'] ?? 'everyone',
            'greeting_enabled' => filter_var($data['greeting_enabled'] ?? false, FILTER_VALIDATE_BOOL),
            'greeting_message' => $this->clean($data['greeting_message'] ?? null),
            'greeting_recipients' => $data['greeting_recipients'] ?? 'everyone',
        ])->save();

        return $profile;
    }

    /**
     * Someone wrote to a business: send its away message or greeting when they apply.
     *
     * @return string|null 'away', 'greeting' or null when nothing was sent
     */
    public function autoReplyTo(Message $incoming): ?string
    {
        if ($incoming->receiver_id === null || (int) $incoming->sender_id === (int) $incoming->receiver_id
            || in_array($incoming->message_type, [Message::TYPE_SYSTEM, Message::TYPE_CALL], true)
            || ! empty($incoming->attachment_meta['auto_reply'])) {
            return null;
        }

        $profile = $this->profileFor((int) $incoming->receiver_id);
        if (! $profile || (! $profile->away_enabled && ! $profile->greeting_enabled)) {
            return null;
        }

        $business = $profile->user;
        $customer = User::query()->find($incoming->sender_id);
        $conversation = $incoming->conversation;
        if (! $business?->isActive() || ! $customer || ! $conversation || $business->hasBlocked($customer) || $business->isBlockedBy($customer)) {
            return null;
        }

        $now = now();
        $kind = null;
        if ($profile->isAwayAt($now) && $this->reaches($profile->away_recipients, $business, $customer)
            && ! $this->sentRecently($conversation, $business, 'away', $now->copy()->subHours(BusinessProfile::AWAY_EVERY_HOURS))) {
            $kind = 'away';
        } elseif ($profile->greeting_enabled && filled($profile->greeting_message)
            && $this->reaches($profile->greeting_recipients, $business, $customer)
            && $this->isNewCustomer($conversation, $incoming, $now)
            && ! $this->sentRecently($conversation, $business, 'greeting', $now->copy()->subDays(BusinessProfile::GREETING_AFTER_DAYS))) {
            $kind = 'greeting';
        }

        if ($kind === null) {
            return null;
        }

        try {
            $this->messages->sendAutoReply($business, $conversation, (string) ($kind === 'away' ? $profile->away_message : $profile->greeting_message), $kind);
        } catch (Throwable $e) {
            Log::warning('Business auto reply failed: '.$e->getMessage(), ['message_id' => $incoming->id]);

            return null;
        }

        return $kind;
    }

    private function reaches(string $recipients, User $business, User $customer): bool
    {
        return $recipients !== 'not_contacts'
            || ! Contact::query()->where('user_id', $business->getKey())->where('contact_user_id', $customer->getKey())->exists();
    }

    private function sentRecently(Conversation $conversation, User $business, string $kind, Carbon $since): bool
    {
        return $conversation->messages()->where('sender_id', $business->getKey())
            ->where('created_at', '>=', $since)
            ->where('attachment_meta->auto_reply', $kind)
            ->exists();
    }

    /** First message from this person, or the first after a long quiet time. */
    private function isNewCustomer(Conversation $conversation, Message $incoming, Carbon $now): bool
    {
        return ! $conversation->messages()
            ->where('id', '<', $incoming->getKey())
            ->where('created_at', '>=', $now->copy()->subDays(BusinessProfile::GREETING_AFTER_DAYS))
            ->where('message_type', '!=', Message::TYPE_SYSTEM)
            ->exists();
    }

    private function clean(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

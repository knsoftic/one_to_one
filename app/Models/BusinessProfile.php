<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * X8 — a business account: what the business does, where and when it is open, and its
 * automatic away and greeting messages.
 */
class BusinessProfile extends Model
{
    public const CATEGORIES = [
        'shop' => 'Shop',
        'restaurant' => 'Restaurant and food',
        'services' => 'Professional services',
        'beauty' => 'Beauty, spa and salon',
        'health' => 'Medical and health',
        'education' => 'Education',
        'travel' => 'Travel and transport',
        'property' => 'Property',
        'automotive' => 'Automotive',
        'events' => 'Events and entertainment',
        'finance' => 'Finance',
        'nonprofit' => 'Non-profit',
        'other' => 'Other',
    ];

    public const DAYS = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];

    public const HOURS_MODES = ['custom' => 'Set hours', 'always' => 'Open 24 hours', 'appointment' => 'By appointment only'];

    public const AWAY_SCHEDULES = ['always' => 'Always', 'outside_hours' => 'Outside business hours', 'custom' => 'Custom time'];

    public const RECIPIENTS = ['everyone' => 'Everyone', 'not_contacts' => 'People not in my contacts'];

    /** A person counts as a new customer again after this long without messages. */
    public const GREETING_AFTER_DAYS = 14;

    /** At most one away message per chat in this time. */
    public const AWAY_EVERY_HOURS = 24;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'hours' => 'array',
            'away_enabled' => 'boolean',
            'greeting_enabled' => 'boolean',
            'away_from' => 'datetime',
            'away_until' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hoursMode(): string
    {
        return array_key_exists($this->hours['mode'] ?? null, self::HOURS_MODES) ? $this->hours['mode'] : 'custom';
    }

    /** @return array{open: bool, from: string, to: string} */
    public function day(string $key): array
    {
        $day = $this->hours['days'][$key] ?? [];

        return [
            'open' => (bool) ($day['open'] ?? false),
            'from' => (string) ($day['from'] ?? '09:00'),
            'to' => (string) ($day['to'] ?? '17:00'),
        ];
    }

    public function hasHours(): bool
    {
        if ($this->hoursMode() !== 'custom') {
            return true;
        }

        return collect(array_keys(self::DAYS))->contains(fn ($key) => $this->day($key)['open']);
    }

    /** Open at this moment (app time zone)? Null when the business shows no hours. */
    public function isOpenAt(Carbon $moment): ?bool
    {
        return match ($this->hoursMode()) {
            'always' => true,
            'appointment' => null,
            default => $this->hasHours() ? $this->openOnCustomHours($moment) : null,
        };
    }

    public function isAwayAt(Carbon $moment): bool
    {
        if (! $this->away_enabled || blank($this->away_message)) {
            return false;
        }

        return match ($this->away_schedule) {
            'outside_hours' => $this->isOpenAt($moment) === false,
            'custom' => $this->away_from !== null && $this->away_until !== null && $moment->between($this->away_from, $this->away_until),
            default => true,
        };
    }

    /**
     * What other people see in the contact info.
     *
     * @return array<string, mixed>
     */
    public function publicPayload(): array
    {
        $now = now();

        return [
            'category' => $this->category,
            'category_label' => self::CATEGORIES[$this->category] ?? ucfirst((string) $this->category),
            'description' => $this->description,
            'address' => $this->address,
            'email' => $this->email,
            'website' => $this->website,
            'hours_mode' => $this->hoursMode(),
            'hours' => $this->hoursMode() === 'custom' && $this->hasHours()
                ? collect(self::DAYS)->map(fn ($label, $key) => ['day' => $label] + $this->day($key))->values()->all()
                : [],
            'open_now' => $this->isOpenAt($now),
        ];
    }

    private function openOnCustomHours(Carbon $moment): bool
    {
        $moment = $moment->copy()->setTimezone(config('app.timezone'));
        $keys = array_keys(self::DAYS);
        $today = $keys[$moment->dayOfWeekIso - 1];
        $time = $moment->format('H:i');

        $day = $this->day($today);
        if ($day['open'] && $this->within($time, $day['from'], $day['to'], sameDay: true)) {
            return true;
        }

        // Hours past midnight from the day before (e.g. 18:00–02:00).
        $yesterday = $this->day($keys[($moment->dayOfWeekIso + 5) % 7]);

        return $yesterday['open'] && $yesterday['to'] <= $yesterday['from'] && $time < $yesterday['to'];
    }

    private function within(string $time, string $from, string $to, bool $sameDay): bool
    {
        if ($from === $to) {
            return true; // open all day
        }

        return $to > $from ? $time >= $from && $time < $to : ($sameDay && $time >= $from);
    }
}

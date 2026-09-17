<?php

namespace App\Services;

use App\Models\AdCampaign;
use App\Models\AdProfile;
use App\Models\Call;
use App\Models\ConversationMember;
use App\Models\Message;
use App\Models\User;
use App\Support\DialCode;
use Illuminate\Support\Arr;

/**
 * Ads (Y1): the user's consent for personalised ads, and the broad, non-sensitive profile built
 * from what they already do in the app — only ever for users who turned personalised ads on.
 */
class AdTargetingService
{
    /** Coarse location is rounded to this many decimals (~1 km) so it is never a precise point. */
    private const LOCATION_DECIMALS = 2;

    /**
     * Record the user's choice. Turning it off deletes the profile straight away.
     */
    public function setConsent(User $user, bool $personalised, array $device = []): void
    {
        $user->forceFill([
            'ads_personalised' => $personalised,
            'ads_consent_at' => now(),
        ])->save();

        if ($personalised) {
            $this->rebuild($user, $device);
        } else {
            $this->forget($user);
        }
    }

    /** Remove everything we keep for ad targeting about this user. */
    public function forget(User $user): void
    {
        AdProfile::query()->whereKey($user->getKey())->delete();
    }

    /**
     * Build or refresh the profile from app data plus what the device reports (time zone, locale,
     * platform, app version, and — only if allowed — a coarse location). Keeps the gender/birth
     * year the user typed. No-op unless personalised ads are on.
     */
    public function rebuild(User $user, array $device = []): ?AdProfile
    {
        if (! $user->ads_personalised) {
            return null;
        }

        $existing = AdProfile::query()->find($user->getKey());
        $timezone = $this->cleanTimezone($device['timezone'] ?? $existing?->timezone);

        $data = [
            'user_id' => $user->getKey(),
            'country' => DialCode::country($user->phone),
            'timezone' => $timezone,
            'region' => $timezone ? $this->regionFromTimezone($timezone) : $existing?->region,
            'locale' => $this->clean($device['locale'] ?? $existing?->locale, 12),
            'platform' => $this->platform($device['platform'] ?? $existing?->platform),
            'os_version' => $this->clean($device['os_version'] ?? $existing?->os_version, 24),
            'app_version' => $this->clean($device['app_version'] ?? $existing?->app_version, 24),
            'interests' => $this->segments($user),
            'updated_at' => now(),
        ];

        // Coarse location only when the user allows it and the device sends coordinates.
        $allowed = array_key_exists('location_allowed', $device) ? (bool) $device['location_allowed'] : (bool) $existing?->location_allowed;
        $data['location_allowed'] = $allowed;
        $data['coarse_location'] = $allowed
            ? ($this->coarse($device['lat'] ?? null, $device['lng'] ?? null) ?? $existing?->coarse_location)
            : null;

        // User-entered, kept unless a new value is given.
        $data['gender'] = array_key_exists('gender', $device) ? $this->gender($device['gender']) : $existing?->gender;
        $data['birth_year'] = array_key_exists('birth_year', $device) ? $this->birthYear($device['birth_year']) : $existing?->birth_year;

        return AdProfile::query()->updateOrCreate(['user_id' => $user->getKey()], $data);
    }

    /**
     * Broad audience segments from the last 90 days of activity (see AdCampaign::SEGMENTS).
     *
     * @return list<string>
     */
    public function segments(User $user): array
    {
        $id = $user->getKey();
        $since = now()->subDays(90);
        $segments = [];

        if ($user->created_at?->gt(now()->subDays(14))) {
            $segments[] = 'new';
        }
        if ($user->businessProfile()->exists()) {
            $segments[] = 'business';
        }

        $sent = Message::query()->where('sender_id', $id)->where('created_at', '>=', $since)->count();
        if ($sent >= 100) {
            $segments[] = 'active';
        }
        if (Message::query()->where('sender_id', $id)->whereNotNull('attachment')->where('created_at', '>=', $since)->count() >= 10) {
            $segments[] = 'media';
        }
        if (ConversationMember::query()->where('user_id', $id)->whereHas('conversation', fn ($q) => $q->where('type', 'group'))->exists()) {
            $segments[] = 'groups';
        }
        if (Call::query()->where(fn ($q) => $q->where('caller_id', $id)->orWhere('callee_id', $id))->exists()) {
            $segments[] = 'callers';
        }

        return array_values(array_intersect(array_keys(AdCampaign::SEGMENTS), $segments));
    }

    private function coarse(mixed $lat, mixed $lng): ?string
    {
        if (! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            return null;
        }

        return round((float) $lat, self::LOCATION_DECIMALS).','.round((float) $lng, self::LOCATION_DECIMALS);
    }

    private function regionFromTimezone(string $timezone): ?string
    {
        $city = Arr::last(explode('/', $timezone));

        return $city ? str_replace('_', ' ', $city) : null;
    }

    private function cleanTimezone(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' && in_array($value, timezone_identifiers_list(), true) ? $value : null;
    }

    private function platform(mixed $value): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($value, ['android', 'ios', 'web'], true) ? $value : ($value === '' ? null : 'web');
    }

    private function gender(mixed $value): ?string
    {
        return in_array($value, ['male', 'female'], true) ? $value : null;
    }

    private function birthYear(mixed $value): ?int
    {
        $year = (int) $value;

        return $year >= (int) date('Y') - 100 && $year <= (int) date('Y') - 13 ? $year : null;
    }

    private function clean(mixed $value, int $max): ?string
    {
        $value = is_string($value) ? trim((string) preg_replace('/[\x00-\x1F]+/', '', $value)) : '';

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}

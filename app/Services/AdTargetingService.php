<?php

namespace App\Services;

use App\Models\AdCampaign;
use App\Models\AdProfile;
use App\Models\Call;
use App\Models\ConversationMember;
use App\Models\Message;
use App\Models\User;
use App\Support\DialCode;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Ads (Y1): the broad profile used to choose which ad a person sees.
 *
 * Everything here is either the app's own data (the country of their own number, what they do in
 * the app, the gender and date of birth they set in Settings → Profile) or something the phone
 * hands over — the connection's IP address, and a rounded location only once the person has
 * granted the location permission, exactly like contacts and camera. No contacts, no messages and
 * no precise position are ever read.
 */
class AdTargetingService
{
    /** Coarse location is rounded to this many decimals (~1 km) so it is never a precise point. */
    private const LOCATION_DECIMALS = 2;

    /**
     * Build or refresh the profile. Called when the app opens and whenever the device sends
     * something new; missing values keep whatever was stored before.
     */
    public function rebuild(User $user, array $device = [], ?Request $request = null): AdProfile
    {
        $existing = AdProfile::query()->find($user->getKey());
        $timezone = $this->cleanTimezone($device['timezone'] ?? $existing?->timezone);

        $data = [
            'user_id' => $user->getKey(),
            'country' => DialCode::country($user->phone),
            'timezone' => $timezone,
            'region' => $timezone ? $this->regionFromTimezone($timezone) : $existing?->region,
            'city' => $this->clean($device['city'] ?? $existing?->city, 64),
            'locale' => $this->clean($device['locale'] ?? $existing?->locale, 12),
            'platform' => $this->platform($device['platform'] ?? $existing?->platform),
            'os_version' => $this->clean($device['os_version'] ?? $existing?->os_version, 24),
            'device_model' => $this->clean($device['device_model'] ?? $existing?->device_model, 64),
            'app_version' => $this->clean($device['app_version'] ?? $existing?->app_version, 24),
            'segments' => $this->segments($user),
            'updated_at' => now(),
        ];

        // The connection's own address, as every web server sees it.
        if ($request) {
            $data['ip'] = $this->clean($request->ip(), 45);
            $data['ip_country'] = $this->ipCountry($request) ?? $existing?->ip_country;
        }

        // Coarse location: only while the phone's location permission is granted.
        $allowed = array_key_exists('location_allowed', $device) ? (bool) $device['location_allowed'] : (bool) $existing?->location_allowed;
        $coarse = $allowed ? $this->coarse($device['lat'] ?? null, $device['lng'] ?? null) : null;

        $data['location_allowed'] = $allowed;
        $data['coarse_location'] = $allowed ? ($coarse ?? $existing?->coarse_location) : null;
        $data['location_at'] = $coarse ? now() : ($allowed ? $existing?->location_at : null);

        return AdProfile::query()->updateOrCreate(['user_id' => $user->getKey()], $data);
    }

    /** Count an app open, so we know how often someone uses the app. */
    public function recordOpen(User $user, array $device = [], ?Request $request = null): AdProfile
    {
        $profile = $this->rebuild($user, $device, $request);

        $profile->forceFill([
            'opens' => (int) $profile->opens + 1,
            'last_open_at' => now(),
        ])->save();

        return $profile;
    }

    /** Remove everything we keep for choosing ads for this person. */
    public function forget(User $user): void
    {
        AdProfile::query()->whereKey($user->getKey())->delete();
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

    /** Country of the connection, when the host (e.g. Cloudflare) tells us. Never guessed. */
    private function ipCountry(Request $request): ?string
    {
        $code = strtoupper(trim((string) $request->header('CF-IPCountry')));

        return preg_match('/^[A-Z]{2}$/', $code) && $code !== 'XX' ? $code : null;
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

    private function clean(mixed $value, int $max): ?string
    {
        $value = is_string($value) ? trim((string) preg_replace('/[\x00-\x1F]+/', '', $value)) : '';

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}

<?php

namespace App\Services;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Push notifications through Firebase Cloud Messaging (HTTP v1).
 *
 * Sends data-only messages: the Android app builds the notification itself
 * (sender photo, conversation style, Reply / Mark as read), exactly the same
 * whether the app is closed, in the background or open.
 *
 * Authenticates with a service account: a short-lived RS256 JWT is exchanged
 * for an OAuth access token, cached until shortly before it expires.
 */
class PushService
{
    public const PRIORITY_HIGH = 'HIGH';

    public const PRIORITY_NORMAL = 'NORMAL';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_CACHE_KEY = 'push:fcm:access-token';

    /** @var array<string, mixed>|false|null */
    private array|false|null $credentials = null;

    /** Is there any way to reach this person outside the open app (Android app or browser)? */
    public function reachable(User|int $user): bool
    {
        return $this->enabled() || app(WebPushService::class)->hasSubscriptions($user);
    }

    public function enabled(): bool
    {
        return $this->credentials() !== null && $this->projectId() !== null;
    }

    /**
     * Try to obtain a Firebase access token (used by `chat:doctor`).
     *
     * @return string|null error message, or null when push works
     */
    public function diagnose(): ?string
    {
        if (! $this->enabled()) {
            return 'Firebase credentials are not configured (FCM_CREDENTIALS).';
        }

        try {
            // Fresh token, not cached: the doctor usually runs as root and must not create cache files.
            $this->accessToken(useCache: false);

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Attach a Firebase token to a device. A token can belong to one device only
     * (the phone may have signed into another account without signing out).
     */
    public function setDeviceToken(DeviceToken $device, string $fcmToken): void
    {
        $hash = DeviceToken::hashToken($fcmToken);

        DeviceToken::query()
            ->where('fcm_token_hash', $hash)
            ->whereKeyNot($device->getKey())
            ->update(['fcm_token' => null, 'fcm_token_hash' => null]);

        $device->forceFill(['fcm_token' => $fcmToken, 'fcm_token_hash' => $hash])->save();
    }

    public function clearDeviceToken(DeviceToken $device): void
    {
        $device->forceFill(['fcm_token' => null, 'fcm_token_hash' => null])->save();
    }

    /**
     * Send a data message to every push-enabled device of a user.
     *
     * @param  array<string, scalar|null>  $data
     * @return int number of devices that accepted the message
     */
    public function sendToUser(User $user, array $data, string $priority = self::PRIORITY_HIGH, ?int $ttlSeconds = null): int
    {
        // X3: browsers that allowed notifications get the same news.
        $sent = app(WebPushService::class)->sendToUser($user, $data, $ttlSeconds);

        if (! $this->enabled()) {
            return $sent;
        }

        foreach ($user->deviceTokens()->whereNotNull('fcm_token')->get() as $device) {
            if ($this->sendToDevice($device, $data, $priority, $ttlSeconds)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * @param  array<string, scalar|null>  $data
     */
    public function sendToDevice(DeviceToken $device, array $data, string $priority = self::PRIORITY_HIGH, ?int $ttlSeconds = null): bool
    {
        $android = array_filter([
            'priority' => $priority,
            'ttl' => $ttlSeconds !== null ? $ttlSeconds.'s' : null,
        ]);

        try {
            $response = Http::withToken($this->accessToken())
                ->acceptJson()
                ->timeout(10)
                ->post('https://fcm.googleapis.com/v1/projects/'.$this->projectId().'/messages:send', [
                    'message' => [
                        'token' => $device->fcm_token,
                        // FCM requires every data value to be a string.
                        'data' => array_map(fn ($value) => (string) ($value ?? ''), $data),
                        'android' => $android,
                    ],
                ]);
        } catch (Throwable $e) {
            Log::warning('Push request failed: '.$e->getMessage(), ['device_id' => $device->id]);

            return false;
        }

        if ($response->successful()) {
            return true;
        }

        if ($this->isStaleToken($response->status(), (array) $response->json())) {
            // App uninstalled or token rotated: the phone registers a new one when opened.
            $this->clearDeviceToken($device);

            return false;
        }

        if ($response->status() === 401) {
            Cache::forget(self::TOKEN_CACHE_KEY);
        }

        Log::warning('Push rejected by Firebase', [
            'device_id' => $device->id,
            'status' => $response->status(),
            'error' => $response->json('error.status'),
        ]);

        return false;
    }

    private function isStaleToken(int $status, array $body): bool
    {
        $codes = collect($body['error']['details'] ?? [])->pluck('errorCode')->filter();

        return $status === 404
            || $codes->contains('UNREGISTERED')
            || ($status === 400 && str_contains((string) ($body['error']['message'] ?? ''), 'registration token'));
    }

    private function accessToken(bool $useCache = true): string
    {
        $cached = $useCache ? Cache::get(self::TOKEN_CACHE_KEY) : null;
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $credentials = $this->credentials() ?? throw new RuntimeException('Push credentials are not configured.');
        $tokenUri = $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token';

        $response = Http::asForm()->timeout(10)->post($tokenUri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $this->signedJwt($credentials, $tokenUri),
        ]);

        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token)) {
            throw new RuntimeException('Could not obtain a Firebase access token (HTTP '.$response->status().').');
        }

        if ($useCache) {
            Cache::put(self::TOKEN_CACHE_KEY, $token, max(60, (int) $response->json('expires_in', 3600) - 300));
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function signedJwt(array $credentials, string $audience): string
    {
        $now = time();
        $payload = $this->base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
            .'.'.$this->base64Url((string) json_encode([
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => $audience,
                'iat' => $now,
                'exp' => $now + 3600,
            ]));

        $key = openssl_pkey_get_private((string) $credentials['private_key']);

        if ($key === false || ! openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Invalid Firebase service-account private key.');
        }

        return $payload.'.'.$this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function projectId(): ?string
    {
        $projectId = config('chat.push.project_id') ?: ($this->credentials()['project_id'] ?? null);

        return is_string($projectId) && $projectId !== '' ? $projectId : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function credentials(): ?array
    {
        if ($this->credentials !== null) {
            return $this->credentials ?: null;
        }

        $path = (string) config('chat.push.credentials');
        if ($path === '') {
            return ($this->credentials = false) ?: null;
        }

        if (! str_starts_with($path, '/') && ! preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            $path = base_path($path);
        }

        $json = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
        $valid = is_array($json) && ! empty($json['client_email']) && ! empty($json['private_key']);

        if (! $valid) {
            Log::warning('FCM_CREDENTIALS is set but the service-account file is missing or invalid.');
        }

        $this->credentials = $valid ? $json : false;

        return $this->credentials ?: null;
    }
}

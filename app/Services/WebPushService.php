<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\User;
use App\Models\WebPushSubscription;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * X3 — Web Push (RFC 8030) with VAPID (RFC 8292) and aes128gcm payload encryption
 * (RFC 8291), using PHP's OpenSSL only. The keys are made once and kept in app settings.
 */
class WebPushService
{
    private const SETTING = 'webpush_vapid';

    /** DER prefix of a P-256 public key (SubjectPublicKeyInfo) before the 65-byte point. */
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    private const RECORD_SIZE = 4096;

    /** Payloads larger than this are cut (push services accept about 4 KB). */
    private const MAX_PAYLOAD = 3000;

    /** @var array{public: string, private: string}|null */
    private ?array $keys = null;

    public function available(): bool
    {
        try {
            return $this->vapidKeys() !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /** The public key browsers subscribe with (base64url, uncompressed P-256 point). */
    public function publicKey(): ?string
    {
        return $this->available() ? $this->vapidKeys()['public'] : null;
    }

    public function hasSubscriptions(User|int $user): bool
    {
        return WebPushSubscription::query()->where('user_id', $user instanceof User ? $user->getKey() : $user)->exists();
    }

    /**
     * @param  array{endpoint: string, keys: array{p256dh: string, auth: string}}  $subscription
     */
    public function subscribe(User $user, array $subscription, ?string $sessionId, ?string $userAgent): WebPushSubscription
    {
        return WebPushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => WebPushSubscription::hashEndpoint($subscription['endpoint'])],
            [
                'user_id' => $user->getKey(),
                'endpoint' => $subscription['endpoint'],
                'public_key' => $subscription['keys']['p256dh'],
                'auth_token' => $subscription['keys']['auth'],
                'session_hash' => WebPushSubscription::hashSession($sessionId),
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 500) : null,
                'last_used_at' => now(),
            ],
        );
    }

    public function unsubscribe(User $user, string $endpoint): void
    {
        WebPushSubscription::query()->where('user_id', $user->getKey())->where('endpoint_hash', WebPushSubscription::hashEndpoint($endpoint))->delete();
    }

    /** A signed-out browser gets no more notifications. */
    public function forgetSession(?string $sessionId): void
    {
        if ($hash = WebPushSubscription::hashSession($sessionId)) {
            WebPushSubscription::query()->where('session_hash', $hash)->delete();
        }
    }

    public function forgetUser(User $user, ?string $keepSessionId = null): void
    {
        WebPushSubscription::query()->where('user_id', $user->getKey())
            ->when($keepSessionId, fn ($q) => $q->where(fn ($w) => $w->whereNull('session_hash')->orWhere('session_hash', '!=', WebPushSubscription::hashSession($keepSessionId))))
            ->delete();
    }

    /**
     * Send the same kind of data as the Firebase push to every browser of the person.
     *
     * @param  array<string, scalar|null>  $data
     * @return int browsers that accepted it
     */
    public function sendToUser(User $user, array $data, ?int $ttlSeconds = null): int
    {
        $payload = $this->payloadFor($data);
        if ($payload === null) {
            return 0;
        }

        $sent = 0;
        foreach (WebPushSubscription::query()->where('user_id', $user->getKey())->get() as $subscription) {
            if ($this->send($subscription, $payload, $ttlSeconds ?? 86400, ($data['type'] ?? '') === 'read' ? 'normal' : 'high')) {
                $sent++;
            }
        }

        return $sent;
    }

    public function send(WebPushSubscription $subscription, array $payload, int $ttl = 86400, string $urgency = 'high'): bool
    {
        try {
            $body = $this->encrypt(
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $this->base64UrlDecode($subscription->public_key),
                $this->base64UrlDecode($subscription->auth_token),
            );

            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => $this->vapidHeader($subscription->endpoint),
                    'Content-Encoding' => 'aes128gcm',
                    'Content-Type' => 'application/octet-stream',
                    'TTL' => (string) max(0, $ttl),
                    'Urgency' => $urgency,
                ])
                ->withBody($body, 'application/octet-stream')
                ->post($subscription->endpoint);
        } catch (Throwable $e) {
            Log::warning('Web push failed: '.$e->getMessage());

            return false;
        }

        // The browser unsubscribed or the subscription expired.
        if (in_array($response->status(), [404, 410], true)) {
            $subscription->delete();

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Web push was refused.', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 300)]);

            return false;
        }

        $subscription->forceFill(['last_used_at' => now()])->saveQuietly();

        return true;
    }

    /**
     * What the service worker shows, from the same data the phones get.
     *
     * @param  array<string, scalar|null>  $data
     * @return array<string, mixed>|null
     */
    public function payloadFor(array $data): ?array
    {
        $base = rtrim((string) config('app.url'), '/');

        return match ($data['type'] ?? null) {
            'message' => [
                'type' => 'message',
                'title' => (string) ($data['sender_name'] ?? config('app.name')),
                'body' => mb_substr((string) ($data['body'] ?? ''), 0, 400) ?: 'New message',
                'tag' => 'conversation-'.(int) ($data['conversation_id'] ?? 0),
                'url' => $base.'/chat/'.(int) ($data['conversation_id'] ?? 0),
                'icon' => $data['avatar_url'] ?? null,
                'silent' => ($data['tone'] ?? null) === 'none',
                'vibrate' => (string) ($data['vibrate'] ?? 'default'),
                'conversation_id' => (int) ($data['conversation_id'] ?? 0),
                'id' => (string) ($data['id'] ?? ''),
            ],
            'read' => ['type' => 'read', 'tag' => 'conversation-'.(int) ($data['conversation_id'] ?? 0)],
            'call' => [
                'type' => 'call',
                'title' => (($data['call_type'] ?? 'audio') === 'video' ? 'Incoming video call' : 'Incoming voice call'),
                'body' => (string) ($data['caller_name'] ?? ''),
                'tag' => 'call-'.(int) ($data['call_id'] ?? 0),
                'url' => $base.'/chat/'.(int) ($data['conversation_id'] ?? 0),
                'icon' => $data['avatar_url'] ?? null,
                'require_interaction' => true,
            ],
            'call_state' => ['type' => 'close', 'tag' => 'call-'.(int) ($data['call_id'] ?? 0)],
            default => null,
        };
    }

    /* ------------------------------------------------------------------ */
    /* Crypto */
    /* ------------------------------------------------------------------ */

    /**
     * RFC 8291 aes128gcm body for one browser.
     */
    public function encrypt(string $plaintext, string $userPublicKey, string $authSecret): string
    {
        if (strlen($userPublicKey) !== 65 || $userPublicKey[0] !== "\x04" || strlen($authSecret) < 16) {
            throw new RuntimeException('The browser subscription keys are not valid.');
        }
        $plaintext = mb_strcut($plaintext, 0, self::MAX_PAYLOAD);

        $local = $this->newKeyPair();
        $localPublic = $this->publicPoint($local);
        $secret = openssl_pkey_derive($this->publicKeyPem($userPublicKey), $local, 32);
        if ($secret === false) {
            throw new RuntimeException('Could not agree on a key with the browser.');
        }

        $keyInfo = "WebPush: info\0".$userPublicKey.$localPublic;
        $ikm = hash_hkdf('sha256', $secret, 32, $keyInfo, $authSecret);
        $salt = random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        $tag = '';
        $cipher = openssl_encrypt($plaintext."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Could not encrypt the notification.');
        }

        return $salt.pack('N', self::RECORD_SIZE).chr(65).$localPublic.$cipher.$tag;
    }

    /** "vapid t=<JWT>, k=<public key>" for a push service. */
    public function vapidHeader(string $endpoint): string
    {
        $keys = $this->vapidKeys() ?? throw new RuntimeException('Browser push is not available on this server.');
        $parts = parse_url($endpoint);
        $audience = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');

        $header = $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = $this->base64UrlEncode(json_encode([
            'aud' => $audience,
            'exp' => time() + 12 * 3600,
            'sub' => $this->subject(),
        ], JSON_UNESCAPED_SLASHES));

        $private = openssl_pkey_get_private(Crypt::decryptString($keys['private']));
        if ($private === false || ! openssl_sign("{$header}.{$claims}", $der, $private, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the push request.');
        }

        return 'vapid t='.$header.'.'.$claims.'.'.$this->base64UrlEncode($this->derToRaw($der)).', k='.$keys['public'];
    }

    /**
     * @return array{public: string, private: string}|null private is encrypted PEM
     */
    public function vapidKeys(): ?array
    {
        if ($this->keys !== null) {
            return $this->keys;
        }

        $saved = AppSetting::get(self::SETTING);
        if (is_array($saved) && isset($saved['public'], $saved['private'])) {
            return $this->keys = $saved;
        }

        $pair = $this->newKeyPair();
        if (! openssl_pkey_export($pair, $pem, null, $this->opensslOptions())) {
            return null;
        }

        $this->keys = ['public' => $this->base64UrlEncode($this->publicPoint($pair)), 'private' => Crypt::encryptString($pem)];
        AppSetting::put([self::SETTING => $this->keys]);

        return $this->keys;
    }

    /** New keys: every browser has to allow notifications again. */
    public function resetKeys(): void
    {
        AppSetting::put([self::SETTING => null]);
        $this->keys = null;
        WebPushSubscription::query()->delete();
    }

    private function subject(): string
    {
        $email = (string) config('mail.from.address');

        return filter_var($email, FILTER_VALIDATE_EMAIL) && ! str_ends_with($email, '@example.com')
            ? 'mailto:'.$email
            : rtrim((string) config('app.url'), '/');
    }

    /** @return \OpenSSLAsymmetricKey */
    private function newKeyPair()
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC] + $this->opensslOptions());
        if ($key === false) {
            throw new RuntimeException('This server cannot make encryption keys (OpenSSL).');
        }

        return $key;
    }

    /** Windows builds of PHP need the path of openssl.cnf to make keys. */
    private function opensslOptions(): array
    {
        if (PHP_OS_FAMILY !== 'Windows' || getenv('OPENSSL_CONF')) {
            return [];
        }

        foreach ([dirname(PHP_BINARY).'/extras/ssl/openssl.cnf', 'C:/xampp/php/extras/ssl/openssl.cnf', 'C:/xampp/apache/conf/openssl.cnf'] as $path) {
            if (is_file($path)) {
                return ['config' => $path];
            }
        }

        return [];
    }

    private function publicPoint($key): string
    {
        $ec = openssl_pkey_get_details($key)['ec'];

        return "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    }

    private function publicKeyPem(string $point): string
    {
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin(self::P256_SPKI_PREFIX).$point), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    /** ECDSA signature: DER SEQUENCE { r, s } to 64 raw bytes. */
    private function derToRaw(string $der): string
    {
        $offset = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7F : 0);
        $read = function () use ($der, &$offset): string {
            $length = ord($der[$offset + 1]);
            $value = substr($der, $offset + 2, $length);
            $offset += 2 + $length;

            return str_pad(ltrim($value, "\0"), 32, "\0", STR_PAD_LEFT);
        };

        return $read().$read();
    }

    public function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }
}

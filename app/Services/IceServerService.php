<?php

namespace App\Services;

use App\Models\User;

/**
 * STUN/TURN servers handed to the browser or app for a call.
 *
 * With a shared secret (coturn "use-auth-secret"), TURN credentials are
 * generated per user and expire, so they are useless if copied:
 *   username   = "<expiry unix time>:<user id>"
 *   credential = base64(HMAC-SHA1(secret, username))
 */
class IceServerService
{
    /**
     * @return list<array{urls: list<string>, username?: string, credential?: string}>
     */
    public function for(User $user): array
    {
        $servers = [];

        $stun = $this->urls(config('chat.calls.stun_urls'));
        if ($stun !== []) {
            $servers[] = ['urls' => $stun];
        }

        $turn = $this->urls(config('chat.calls.turn_urls'));
        if ($turn === []) {
            return $servers;
        }

        $credentials = $this->turnCredentials((string) $user->getKey());
        if ($credentials !== null) {
            $servers[] = ['urls' => $turn, ...$credentials];
        }

        return $servers;
    }

    /**
     * TURN username and password: made from the shared secret (valid for a while), or the fixed pair.
     *
     * @return array{username: string, credential: string}|null
     */
    public function turnCredentials(string $label, ?int $ttlSeconds = null): ?array
    {
        $secret = (string) config('chat.calls.turn_secret');

        if ($secret !== '') {
            $username = (time() + max(300, $ttlSeconds ?? (int) config('chat.calls.turn_ttl_seconds', 43200))).':'.$label;
            $credential = base64_encode(hash_hmac('sha1', $username, $secret, true));
        } else {
            $username = (string) config('chat.calls.turn_username');
            $credential = (string) config('chat.calls.turn_password');
        }

        return $username !== '' && $credential !== '' ? ['username' => $username, 'credential' => $credential] : null;
    }

    /**
     * @return list<string>
     */
    public function turnUrls(): array
    {
        return $this->urls(config('chat.calls.turn_urls'));
    }

    /**
     * @return list<string>
     */
    private function urls(mixed $value): array
    {
        return collect(explode(',', (string) $value))
            ->map(fn ($url) => trim($url))
            ->filter(fn ($url) => preg_match('/^(stun|turns?):[^\s]+$/i', $url))
            ->values()
            ->all();
    }
}

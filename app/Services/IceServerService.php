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

        $secret = (string) config('chat.calls.turn_secret');

        if ($secret !== '') {
            $username = (time() + max(300, (int) config('chat.calls.turn_ttl_seconds', 43200))).':'.$user->getKey();
            $credential = base64_encode(hash_hmac('sha1', $username, $secret, true));
        } else {
            $username = (string) config('chat.calls.turn_username');
            $credential = (string) config('chat.calls.turn_password');
        }

        if ($username !== '' && $credential !== '') {
            $servers[] = ['urls' => $turn, 'username' => $username, 'credential' => $credential];
        }

        return $servers;
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

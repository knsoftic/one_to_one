<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Support\Stun\TurnProbe;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * The app's own TURN server (coturn): what is set up in Admin → App settings, and a real check
 * that it hands out relays with the app's credentials (the way phones use it in a call).
 */
class TurnServerService
{
    private const CHECK_CACHE = 'admin:turn-check';

    /** Relays checked at most (each address is one allocation). */
    private const MAX_CHECKS = 6;

    public function __construct(
        private readonly IceServerService $ice,
        private readonly AppConfigService $config,
        private readonly TurnProbe $probe,
    ) {}

    /**
     * The TURN addresses as parts.
     *
     * @return list<array{url: string, host: string, port: int, transport: 'udp'|'tcp'|'tls'}>
     */
    public function endpoints(): array
    {
        $endpoints = [];
        foreach ($this->ice->turnUrls() as $url) {
            if (! preg_match('#^(turns?):(\[[0-9a-f:.]+\]|[^:?\s]+)(?::(\d{1,5}))?(?:\?transport=(udp|tcp))?$#i', $url, $m)) {
                continue;
            }
            $secure = strtolower($m[1]) === 'turns';
            $endpoints[] = [
                'url' => $url,
                'host' => trim($m[2], '[]'),
                'port' => (int) (($m[3] ?? '') !== '' ? $m[3] : ($secure ? 5349 : 3478)),
                'transport' => $secure ? 'tls' : strtolower($m[4] ?? 'udp'),
            ];
        }

        return $endpoints;
    }

    /**
     * @return array{configured: bool, auth: ?string, from_admin: bool, endpoints: list<array<string, mixed>>, check: ?array<string, mixed>}
     */
    public function status(): array
    {
        $endpoints = $this->endpoints();
        $auth = (string) config('chat.calls.turn_secret') !== '' ? 'secret'
            : ((string) config('chat.calls.turn_username') !== '' && (string) config('chat.calls.turn_password') !== '' ? 'password' : null);

        return [
            'configured' => $endpoints !== [] && $auth !== null,
            'auth' => $auth,
            'from_admin' => AppSetting::get('turn_urls') !== null,
            'endpoints' => $endpoints,
            'check' => $this->lastCheck(),
        ];
    }

    /**
     * Ask every TURN address for a relay with the app's credentials, like a phone in a call.
     *
     * @return array{at: string, ok: bool, results: list<array<string, mixed>>}
     */
    public function check(float $budgetSeconds = 20.0): array
    {
        $credentials = $this->ice->turnCredentials('admin-check', 600);
        $results = [];
        // The admin page waits for this: stay well inside PHP's 30 second limit.
        $deadline = microtime(true) + $budgetSeconds;

        foreach (array_slice($this->endpoints(), 0, self::MAX_CHECKS) as $endpoint) {
            $left = $deadline - microtime(true);
            $result = match (true) {
                $credentials === null => ['ok' => false, 'problem' => 'no_credentials', 'detail' => 'No shared secret (or username and password) is saved.', 'relay' => null, 'ms' => null],
                $left < 1.5 => ['ok' => false, 'problem' => 'skipped', 'detail' => 'Not checked: the other addresses took too long. Check again.', 'relay' => null, 'ms' => null],
                default => $this->probe->allocate($endpoint['host'], $endpoint['port'], $endpoint['transport'], $credentials['username'], $credentials['credential'], min(3.0, $left / 3)),
            };
            $results[] = $endpoint + $result;
        }

        $check = [
            'at' => now()->toIso8601String(),
            'ok' => $results !== [] && collect($results)->every(fn ($result) => $result['ok']),
            'results' => $results,
        ];
        // The dashboard shows the last check (no network calls on page views).
        Cache::put(self::CHECK_CACHE, $check + ['settings' => $this->fingerprint()], now()->addDays(7));

        return $check;
    }

    /**
     * The last check, while the TURN addresses and credentials are still the same.
     *
     * @return array{at: Carbon, ok: bool, results: list<array<string, mixed>>}|null
     */
    public function lastCheck(): ?array
    {
        $check = Cache::get(self::CHECK_CACHE);
        if (! is_array($check) || ! hash_equals($this->fingerprint(), (string) ($check['settings'] ?? ''))) {
            return null;
        }

        return ['at' => Carbon::parse($check['at']), 'ok' => (bool) $check['ok'], 'results' => $check['results'] ?? []];
    }

    /** Changes when the addresses, secret, username or password change (never stores them). */
    private function fingerprint(): string
    {
        return hash_hmac('sha256', json_encode([
            $this->ice->turnUrls(),
            (string) config('chat.calls.turn_secret'),
            (string) config('chat.calls.turn_username'),
            (string) config('chat.calls.turn_password'),
        ]), (string) config('app.key'));
    }

    /**
     * Save the server made by scripts/setup-turn.sh (addresses + shared secret) as the app settings.
     *
     * @param  list<string>  $urls
     * @return list<string> the settings that changed
     */
    public function saveServer(array $urls, string $secret): array
    {
        Cache::forget(self::CHECK_CACHE);

        return $this->config->update(
            ['turn_urls' => implode(',', $urls), 'turn_secret' => $secret],
            // A shared secret replaces a fixed username and password.
            ['turn_username', 'turn_password'],
        );
    }

    /**
     * TURN servers with short-lived credentials, for the admin's browser test.
     *
     * @return list<array{urls: list<string>, username: string, credential: string}>
     */
    public function browserTestServers(string $label): array
    {
        $urls = $this->ice->turnUrls();
        $credentials = $this->ice->turnCredentials($label, 600);

        return $urls === [] || $credentials === null ? [] : [['urls' => $urls, ...$credentials]];
    }
}

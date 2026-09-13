<?php

namespace App\Console\Commands;

use App\Models\Call;
use App\Models\DeviceToken;
use App\Services\PushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Checks everything live updates, phone notifications and calls depend on,
 * and says how to fix what is wrong.
 */
class ChatDoctor extends Command
{
    public const SCHEDULER_HEARTBEAT_KEY = 'chat:scheduler:last-run';

    protected $signature = 'chat:doctor';

    protected $description = 'Check live updates (Reverb), push notifications, calls (TURN) and the scheduler';

    private int $failures = 0;

    private int $warnings = 0;

    public function handle(PushService $push): int
    {
        $this->section('Application');
        $this->checkApplication();

        $this->section('Live updates (Laravel Reverb WebSockets)');
        $this->checkRealtime();

        $this->section('Phone notifications (Firebase)');
        $this->checkPush($push);

        $this->section('Calls');
        $this->checkCalls();

        $this->section('Scheduler (cron)');
        $this->checkScheduler();

        $this->newLine();
        if ($this->failures === 0 && $this->warnings === 0) {
            $this->info('Everything looks good.');
        } else {
            $this->line(sprintf('<fg=red>%d problem(s)</>, <fg=yellow>%d warning(s)</> — fix them from the top down.', $this->failures, $this->warnings));
        }

        return $this->failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /* ------------------------------------------------------------------ */

    private function checkApplication(): void
    {
        $url = (string) config('app.url');
        $https = str_starts_with($url, 'https://');

        $this->result($https, "APP_URL is {$url}", 'Set APP_URL to the https:// address of the site.', warnOnly: ! app()->isProduction());
        $this->result(! config('app.debug') || ! app()->isProduction(), 'APP_DEBUG is off in production', 'Set APP_DEBUG=false.');

        if ($https) {
            $this->result((bool) config('session.secure'), 'Session cookie is HTTPS-only', 'Set SESSION_SECURE_COOKIE=true, then php artisan config:cache.', warnOnly: true);
        }

        try {
            DB::connection()->getPdo();
            $missing = collect(['device_tokens', 'calls', 'call_signals'])->reject(fn ($table) => Schema::hasTable($table));
            $this->result($missing->isEmpty(), 'Database migrated', 'Run php artisan migrate --force (missing: '.$missing->implode(', ').').');
        } catch (Throwable $e) {
            $this->result(false, 'Database connection', $e->getMessage());
        }
    }

    private function checkRealtime(): void
    {
        $reverb = config('broadcasting.connections.reverb', []);
        $options = $reverb['options'] ?? [];
        $host = (string) ($options['host'] ?? '');
        $port = (int) ($options['port'] ?? 443);
        $scheme = (string) ($options['scheme'] ?? 'https');
        $appHost = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        $isReverb = config('broadcasting.default') === 'reverb' && ! empty($reverb['key']) && ! empty($reverb['secret']);
        $this->result($isReverb, 'Broadcasting uses Reverb', 'Set BROADCAST_CONNECTION=reverb and REVERB_APP_ID / REVERB_APP_KEY / REVERB_APP_SECRET (or run scripts/setup-realtime.sh).');
        if (! $isReverb) {
            return;
        }

        $local = in_array($host, ['127.0.0.1', 'localhost', '0.0.0.0', ''], true);
        $publicSite = $appHost !== '' && ! in_array($appHost, ['127.0.0.1', 'localhost'], true);
        $this->result(
            ! ($local && $publicSite),
            "Browsers and phones connect to {$scheme}://{$host}:{$port}",
            "REVERB_HOST points to this server only. Set REVERB_HOST={$appHost}, REVERB_PORT=443, REVERB_SCHEME=https (or run scripts/setup-realtime.sh).",
        );

        // Reverb process on this server.
        $serverHost = (string) env('REVERB_SERVER_HOST', '127.0.0.1');
        $serverHost = $serverHost === '0.0.0.0' ? '127.0.0.1' : $serverHost;
        $serverPort = (int) env('REVERB_SERVER_PORT', 8080);
        $socket = @fsockopen($serverHost, $serverPort, $errno, $error, 3);
        if ($socket) {
            fclose($socket);
        }
        $this->result((bool) $socket, "Reverb is running on {$serverHost}:{$serverPort}", 'Start Reverb and keep it running (Supervisor, guide step 11, or scripts/setup-realtime.sh).');

        // Laravel → Reverb (broadcasting events).
        try {
            Broadcast::connection('reverb')->getPusher()->getChannels();
            $this->result(true, 'Laravel can send events to Reverb', '');
        } catch (Throwable $e) {
            $this->result(false, 'Laravel can send events to Reverb', 'Broadcast request failed: '.str($e->getMessage())->limit(160).' — check the Nginx /apps/ proxy (guide step 10) and REVERB_* values.');
        }

        // Browsers/phones → Reverb through Nginx.
        if (! $local) {
            $status = $this->websocketHandshake($host, $port, $scheme === 'https', (string) $reverb['key']);
            $hint = match (true) {
                $status === 404 => 'Nginx answers 404 for /app/: add the Reverb proxy blocks (guide step 10) or run scripts/setup-realtime.sh.',
                $status === 502 || $status === 504 => 'Nginx cannot reach Reverb: start Reverb (Supervisor).',
                $status === null => "Could not connect to {$host}:{$port}.",
                default => "Unexpected HTTP {$status} instead of 101.",
            };
            $this->result($status === 101, "WebSocket connection to wss://{$host}/app/ works", $hint);
        }
    }

    private function checkPush(PushService $push): void
    {
        if (! $push->enabled()) {
            $this->result(false, 'Firebase push configured', 'Phones only get notifications through the fallback connection. Set FCM_CREDENTIALS (guide step 18).', warnOnly: true);

            return;
        }

        $error = $push->diagnose();
        $this->result($error === null, 'Firebase accepts the service account', (string) $error);

        if (! Schema::hasTable('device_tokens')) {
            return;
        }

        $devices = DeviceToken::query()->count();
        $withPush = DeviceToken::query()->whereNotNull('fcm_token')->count();
        $this->line("   {$devices} phone(s) signed in, {$withPush} registered for Firebase push");

        if ($devices > $withPush) {
            $this->result(
                false,
                'All phones use Firebase push',
                ($devices - $withPush).' phone(s) still use the fallback connection. They switch automatically the next time the app is opened with the latest version.',
                warnOnly: true,
            );
        }
    }

    private function checkCalls(): void
    {
        $this->result((bool) config('chat.calls.enabled', true), 'Calls are enabled', 'Set CHAT_CALLS_ENABLED=true.', warnOnly: true);

        $turnUrls = collect(explode(',', (string) config('chat.calls.turn_urls')))->map(fn ($url) => trim($url))->filter();
        $hasCredentials = (string) config('chat.calls.turn_secret') !== '' || (string) config('chat.calls.turn_username') !== '';

        if ($turnUrls->isEmpty() || ! $hasCredentials) {
            $this->result(false, 'TURN server configured', 'Without TURN, calls on mobile data and strict networks often fail to connect. Run scripts/setup-turn.sh (guide step 19).', warnOnly: true);
        } else {
            $checked = [];
            foreach ($turnUrls as $url) {
                if (! preg_match('/^turns?:([^:?]+)(?::(\d+))?/i', $url, $match) || str_starts_with(strtolower($url), 'turns:')) {
                    continue;
                }
                $target = $match[1].':'.($match[2] ?? 3478);
                if (isset($checked[$target])) {
                    continue;
                }
                $checked[$target] = true;
                $this->result($this->stunBinding($match[1], (int) ($match[2] ?? 3478)), "TURN server answers on {$target} (UDP)", 'coturn is not running or UDP 3478 is blocked: systemctl status coturn, and open the ports (guide step 19.3).');
            }
        }

        if (Schema::hasTable('calls')) {
            $stale = Call::query()->active()->where('created_at', '<', now()->subHours(3))->count();
            $this->result($stale === 0, 'No abandoned calls', "{$stale} call(s) still marked active: make sure the scheduler runs (php artisan chat:expire-calls).", warnOnly: true);
        }
    }

    private function checkScheduler(): void
    {
        $lastRun = Cache::get(self::SCHEDULER_HEARTBEAT_KEY);
        $recent = $lastRun && now()->diffInSeconds($lastRun, true) < 180;

        $this->result(
            (bool) $recent,
            $lastRun ? 'Scheduler last ran '.now()->parse($lastRun)->diffForHumans() : 'Scheduler has run',
            'Add the cron task from guide step 12 (runs php artisan schedule:run every minute).',
        );
    }

    /* ------------------------------------------------------------------ */

    /**
     * Open a WebSocket to Reverb the way a browser does.
     *
     * @return int|null HTTP status (101 = upgraded), null when unreachable
     */
    private function websocketHandshake(string $host, int $port, bool $tls, string $key): ?int
    {
        $context = stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true]]);
        $socket = @stream_socket_client(($tls ? 'ssl://' : 'tcp://')."{$host}:{$port}", $errno, $error, 6, STREAM_CLIENT_CONNECT, $context);

        if (! $socket) {
            return null;
        }

        stream_set_timeout($socket, 6);
        $origin = rtrim((string) config('app.url'), '/');
        fwrite($socket, implode("\r\n", [
            'GET /app/'.rawurlencode($key).'?protocol=7&client=chat-doctor&version=1.0&flash=false HTTP/1.1',
            "Host: {$host}",
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: '.base64_encode(random_bytes(16)),
            'Sec-WebSocket-Version: 13',
            "Origin: {$origin}",
            '',
            '',
        ]));

        $line = (string) fgets($socket);
        fclose($socket);

        return preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $line, $match) ? (int) $match[1] : null;
    }

    /** STUN binding request (coturn answers without credentials). */
    private function stunBinding(string $host, int $port): bool
    {
        $socket = @stream_socket_client("udp://{$host}:{$port}", $errno, $error, 3);
        if (! $socket) {
            return false;
        }

        stream_set_timeout($socket, 3);
        $transaction = random_bytes(12);
        fwrite($socket, pack('nnN', 0x0001, 0, 0x2112A442).$transaction);
        $response = (string) fread($socket, 512);
        fclose($socket);

        return strlen($response) >= 20 && unpack('n', substr($response, 0, 2))[1] === 0x0101 && substr($response, 8, 12) === $transaction;
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("<options=bold>{$title}</>");
    }

    private function result(bool $ok, string $label, string $hint, bool $warnOnly = false): void
    {
        if ($ok) {
            $this->line("  <fg=green>✔</> {$label}");

            return;
        }

        if ($warnOnly) {
            $this->warnings++;
            $this->line("  <fg=yellow>⚠</> {$label}");
        } else {
            $this->failures++;
            $this->line("  <fg=red>✘</> {$label}");
        }

        if ($hint !== '') {
            $this->line("     <fg=gray>→ {$hint}</>");
        }
    }
}

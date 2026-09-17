<?php

namespace App\Console\Commands;

use App\Services\TurnServerService;
use Illuminate\Console\Command;

/**
 * Connects the app to its own TURN server (used by scripts/setup-turn.sh), and checks it.
 *
 *   printf '%s' "$SECRET" | php artisan chat:turn-server --urls="turn:host:3478?transport=udp,…" --secret-stdin
 *   php artisan chat:turn-server --check
 */
class TurnServer extends Command
{
    protected $signature = 'chat:turn-server
        {--urls= : TURN addresses, comma separated (turn:host:3478?transport=udp, turns:host:5349?transport=tcp)}
        {--secret-stdin : Read the coturn static-auth-secret from standard input (never from the command line)}
        {--check : Ask the TURN server for a relay with the saved settings}';

    protected $description = 'Save the TURN server (address + shared secret) in the admin settings, or check it';

    public function handle(TurnServerService $turn): int
    {
        if ($this->option('urls') !== null) {
            $urls = collect(explode(',', (string) $this->option('urls')))->map(fn ($url) => trim($url))->filter()->values();
            $invalid = $urls->reject(fn ($url) => preg_match('#^turns?:[^\s,]+$#i', $url));
            if ($urls->isEmpty() || $invalid->isNotEmpty()) {
                $this->error('Give TURN addresses like turn:chat.example.com:3478?transport=udp'.($invalid->isNotEmpty() ? ' (not valid: '.$invalid->implode(', ').')' : ''));

                return self::FAILURE;
            }

            $secret = $this->option('secret-stdin') ? trim((string) stream_get_contents(STDIN)) : '';
            if (strlen($secret) < 16) {
                $this->error('Pipe the shared secret (at least 16 characters) into --secret-stdin.');

                return self::FAILURE;
            }

            $changed = $turn->saveServer($urls->all(), $secret);
            $this->line('  <fg=green>✔</> Saved in Admin → App settings → Call server (TURN): '.$urls->implode(', ').($changed === [] ? ' (no changes)' : ''));
        }

        if (! $this->option('check')) {
            return self::SUCCESS;
        }

        $status = $turn->status();
        if (! $status['configured']) {
            $this->error('No TURN server is set up. Run scripts/setup-turn.sh as root, or fill in Admin → App settings → Call server (TURN).');

            return self::FAILURE;
        }

        $check = $turn->check();
        foreach ($check['results'] as $result) {
            $this->line(sprintf('  %s %s  %s', $result['ok'] ? '<fg=green>✔</>' : '<fg=red>✘</>', $result['url'], $result['detail']));
        }

        return $check['ok'] ? self::SUCCESS : self::FAILURE;
    }
}

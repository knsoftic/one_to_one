<?php

namespace App\Broadcasting;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Broadcasting must never break (or slow down) the request that triggered it.
 *
 * If the WebSocket server is unavailable the data is still saved and clients
 * catch up through the AJAX polling fallback. After a failure, broadcasting is
 * paused briefly (circuit breaker) so requests don't wait on connection
 * timeouts again and again.
 */
class ResilientBroadcastManager extends BroadcastManager
{
    private const CIRCUIT_KEY = 'chat:broadcast-circuit-open';

    private const CIRCUIT_SECONDS = 10;

    public function queue($event)
    {
        if ($this->circuitOpen()) {
            return;
        }

        try {
            parent::queue($event);
        } catch (Throwable $e) {
            $this->openCircuit();

            Log::warning('Realtime broadcast failed: '.$e->getMessage(), [
                'event' => is_object($event) ? $event::class : (string) $event,
            ]);
        }
    }

    private function circuitOpen(): bool
    {
        try {
            return Cache::has(self::CIRCUIT_KEY);
        } catch (Throwable) {
            return false;
        }
    }

    private function openCircuit(): void
    {
        try {
            Cache::put(self::CIRCUIT_KEY, true, now()->addSeconds(self::CIRCUIT_SECONDS));
        } catch (Throwable) {
            // Cache unavailable: nothing else to do.
        }
    }
}

<?php

namespace App\Support\Stun;

/**
 * Asks a TURN server for a relay address with real credentials, like a phone at the start of a
 * call, then frees it again. Tells apart: no answer (not running / port closed), wrong secret,
 * and a working relay.
 */
class TurnProbe
{
    /** REQUESTED-TRANSPORT: UDP (the relay the calls use). */
    private const TRANSPORT_UDP = "\x11\0\0\0";

    /**
     * @param  'udp'|'tcp'|'tls'  $transport
     * @return array{ok: bool, problem: ?string, detail: string, relay: ?string, ms: ?int}
     */
    public function allocate(string $host, int $port, string $transport, string $username, string $password, float $timeout = 4.0): array
    {
        $started = microtime(true);
        $socket = $this->connect($host, $port, $transport, $timeout, $error);
        if ($socket === null) {
            return $this->result(false, 'unreachable', $error ?: "Could not connect to {$host}:{$port}.");
        }

        try {
            // 1. Without credentials: the server answers 401 with its realm and a nonce.
            $first = $this->request($socket, $transport, new StunMessage(StunMessage::ALLOCATE_REQUEST, StunMessage::transactionId(), [
                [StunMessage::ATTR_REQUESTED_TRANSPORT, self::TRANSPORT_UDP],
            ]), null, $timeout);

            if ($first === null) {
                return $this->result(false, 'no_answer', $transport === 'udp'
                    ? "No answer from {$host}:{$port} over UDP: coturn is not running, or UDP {$port} is closed in a firewall."
                    : "{$host}:{$port} accepted the connection but did not answer like a TURN server.");
            }
            if ($first->type === StunMessage::ALLOCATE_SUCCESS) {
                // Free it again, then warn: anyone on the internet can relay through this server.
                $this->request($socket, $transport, new StunMessage(StunMessage::REFRESH_REQUEST, StunMessage::transactionId(), [
                    [StunMessage::ATTR_LIFETIME, pack('N', 0)],
                ]), null, min($timeout, 1.5));

                return $this->result(false, 'open_relay', 'The server gives relays without a password, so anyone can use it: turn on use-auth-secret in turnserver.conf.', $first->xorAddress(StunMessage::ATTR_XOR_RELAYED_ADDRESS), $started);
            }

            $realm = $first->get(StunMessage::ATTR_REALM);
            $nonce = $first->get(StunMessage::ATTR_NONCE);
            if (($first->errorCode()['code'] ?? 0) !== 401 || $realm === null || $nonce === null) {
                return $this->result(false, 'unexpected', 'The server refused the request: '.$this->describe($first));
            }

            // 2. With credentials (one retry when the nonce went stale).
            $key = StunMessage::longTermKey($username, $realm, $password);
            for ($attempt = 0; $attempt < 2; $attempt++) {
                [$answer, $raw] = $this->authenticated($socket, $transport, StunMessage::ALLOCATE_REQUEST, $username, $realm, $nonce, $key, [
                    [StunMessage::ATTR_REQUESTED_TRANSPORT, self::TRANSPORT_UDP],
                ], $timeout);
                if ($answer === null) {
                    return $this->result(false, 'no_answer', 'The server stopped answering after the password was sent.');
                }
                if (($answer->errorCode()['code'] ?? 0) === 438 && ($fresh = $answer->get(StunMessage::ATTR_NONCE)) !== null) {
                    $nonce = $fresh;

                    continue;
                }
                break;
            }

            if ($answer->type !== StunMessage::ALLOCATE_SUCCESS) {
                $code = $answer->errorCode()['code'] ?? 0;

                return match ($code) {
                    401 => $this->result(false, 'wrong_secret', 'The TURN server did not accept the username and password: the shared secret in the app is not the static-auth-secret in turnserver.conf.'),
                    486 => $this->result(false, 'quota', 'The TURN server has too many relays open for this user (486). Try again in a few minutes.'),
                    508 => $this->result(false, 'capacity', 'The TURN server is out of relay ports (508): widen min-port / max-port.'),
                    default => $this->result(false, 'refused', 'The TURN server refused the relay: '.$this->describe($answer)),
                };
            }
            if (! StunMessage::hasValidIntegrity($raw, $key)) {
                return $this->result(false, 'unexpected', 'The answer was not signed with this password.');
            }

            $relay = $answer->xorAddress(StunMessage::ATTR_XOR_RELAYED_ADDRESS);

            // 3. Free the relay straight away.
            $this->authenticated($socket, $transport, StunMessage::REFRESH_REQUEST, $username, $realm, $nonce, $key, [
                [StunMessage::ATTR_LIFETIME, pack('N', 0)],
            ], min($timeout, 1.5));

            return $this->result(true, null, 'Relay address '.($relay ?? 'given').'.', $relay, $started);
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param  list<array{0: int, 1: string}>  $attributes
     * @return array{0: ?StunMessage, 1: string}
     */
    private function authenticated($socket, string $transport, int $type, string $username, string $realm, string $nonce, string $key, array $attributes, float $timeout): array
    {
        $message = new StunMessage($type, StunMessage::transactionId(), [
            ...$attributes,
            [StunMessage::ATTR_USERNAME, $username],
            [StunMessage::ATTR_REALM, $realm],
            [StunMessage::ATTR_NONCE, $nonce],
        ]);
        $raw = '';
        $answer = $this->request($socket, $transport, $message, $key, $timeout, $raw);

        return [$answer, $raw];
    }

    /** Sends a request and waits for the answer with the same transaction (UDP: sent up to 3 times). */
    private function request($socket, string $transport, StunMessage $message, ?string $key, float $timeout, string &$raw = ''): ?StunMessage
    {
        $bytes = $message->encode($key, true);
        $deadline = microtime(true) + $timeout;
        $tries = $transport === 'udp' ? 3 : 1;

        for ($try = 0; $try < $tries; $try++) {
            if (@fwrite($socket, $bytes) === false) {
                return null;
            }
            $wait = $transport === 'udp' ? min($timeout / $tries, $deadline - microtime(true)) : $deadline - microtime(true);
            while ($wait > 0) {
                $raw = $transport === 'udp' ? $this->readDatagram($socket, $wait) : $this->readStream($socket, $wait);
                if ($raw === '') {
                    break;
                }
                $answer = StunMessage::decode($raw);
                if ($answer !== null && hash_equals($message->transaction, $answer->transaction)) {
                    return $answer;
                }
                $wait = $deadline - microtime(true);
            }
        }

        return null;
    }

    private function readDatagram($socket, float $wait): string
    {
        $this->setTimeout($socket, $wait);

        return (string) @fread($socket, 2048);
    }

    private function readStream($socket, float $wait): string
    {
        $this->setTimeout($socket, $wait);
        $header = $this->readExactly($socket, 20);
        if (strlen($header) < 20) {
            return '';
        }
        $length = unpack('n', substr($header, 2, 2))[1];

        return $header.$this->readExactly($socket, min($length, 4096));
    }

    private function readExactly($socket, int $bytes): string
    {
        $data = '';
        while (strlen($data) < $bytes) {
            $chunk = @fread($socket, $bytes - strlen($data));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function setTimeout($socket, float $seconds): void
    {
        $seconds = max(0.05, $seconds);
        stream_set_timeout($socket, (int) floor($seconds), (int) (($seconds - floor($seconds)) * 1_000_000));
    }

    /** @return resource|null */
    private function connect(string $host, int $port, string $transport, float $timeout, ?string &$error)
    {
        $error = null;
        $target = str_contains($host, ':') ? "[{$host}]:{$port}" : "{$host}:{$port}";
        $context = stream_context_create(['ssl' => [
            'peer_name' => $host,
            'SNI_enabled' => true,
            'verify_peer' => true,
            'verify_peer_name' => true,
        ]]);
        $scheme = ['udp' => 'udp', 'tcp' => 'tcp', 'tls' => 'tls'][$transport] ?? 'udp';

        $socket = @stream_socket_client("{$scheme}://{$target}", $errno, $message, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (! $socket) {
            $error = match ($transport) {
                'tls' => "TLS connection to {$target} failed ({$message}): port closed, or the certificate is not valid for {$host}.",
                'tcp' => "Could not connect to {$target} over TCP ({$message}): port closed or coturn not running.",
                default => "Could not open UDP to {$target} ({$message}).",
            };

            return null;
        }

        return $socket;
    }

    private function describe(StunMessage $message): string
    {
        $error = $message->errorCode();

        return $error ? trim($error['code'].' '.$error['reason']) : sprintf('message type 0x%04x', $message->type);
    }

    /**
     * @return array{ok: bool, problem: ?string, detail: string, relay: ?string, ms: ?int}
     */
    private function result(bool $ok, ?string $problem, string $detail, ?string $relay = null, ?float $started = null): array
    {
        return [
            'ok' => $ok,
            'problem' => $problem,
            'detail' => $detail,
            'relay' => $relay,
            'ms' => $started ? (int) round((microtime(true) - $started) * 1000) : null,
        ];
    }
}

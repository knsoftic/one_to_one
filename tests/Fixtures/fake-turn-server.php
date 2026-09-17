<?php

/*
 * A tiny TURN server for tests (UDP and TCP on 127.0.0.1): answers Allocate and Refresh with
 * coturn's shared-secret rules (401 + realm/nonce first, then checks MESSAGE-INTEGRITY).
 *
 * php fake-turn-server.php <port> <static-auth-secret> [seconds to keep running when idle, default 60]
 */

use App\Support\Stun\StunMessage;

require __DIR__.'/../../vendor/autoload.php';

[, $port, $secret] = $argv;
const REALM = 'turn.test';
const NONCE = 'nonce-123';

$udp = stream_socket_server("udp://127.0.0.1:{$port}", $errno, $error, STREAM_SERVER_BIND);
$tcp = stream_socket_server("tcp://127.0.0.1:{$port}", $errno2, $error2);
if (! $udp || ! $tcp) {
    fwrite(STDERR, "bind failed: {$error} {$error2}\n");
    exit(1);
}
echo "ready\n";
fflush(STDOUT);

$answer = function (string $bytes, string $peer) use ($secret): ?string {
    $request = StunMessage::decode($bytes);
    if ($request === null) {
        return null;
    }
    $method = $request->type & 0x3EEF;
    $errorType = $request->type | 0x0110;
    $successType = $request->type | 0x0100;

    $unauthorized = fn () => (new StunMessage($errorType, $request->transaction, [
        [StunMessage::ATTR_ERROR_CODE, pack('nCC', 0, 4, 1).'Unauthorized'],
        [StunMessage::ATTR_REALM, REALM],
        [StunMessage::ATTR_NONCE, NONCE],
    ]))->encode(null, true);

    $username = $request->get(StunMessage::ATTR_USERNAME);
    // secret "open": a badly set up server that relays for anyone, without a password.
    if ($username === null && $secret === 'open') {
        return (new StunMessage($successType, $request->transaction, [
            [StunMessage::ATTR_XOR_RELAYED_ADDRESS, "\0\x01".pack('n', 49170 ^ (StunMessage::MAGIC_COOKIE >> 16)).(inet_pton('203.0.113.7') ^ pack('N', StunMessage::MAGIC_COOKIE))],
            [StunMessage::ATTR_LIFETIME, pack('N', 600)],
        ]))->encode(null, true);
    }
    if ($username === null) {
        return $unauthorized();
    }

    // coturn use-auth-secret: password = base64(HMAC-SHA1(secret, username)).
    $key = StunMessage::longTermKey($username, REALM, base64_encode(hash_hmac('sha1', $username, $secret, true)));
    if (! StunMessage::hasValidIntegrity($bytes, $key)) {
        return $unauthorized();
    }

    if ($method === StunMessage::ALLOCATE_REQUEST) {
        $xor = function (string $ip, int $port): string {
            return "\0\x01".pack('n', $port ^ (StunMessage::MAGIC_COOKIE >> 16)).(inet_pton($ip) ^ pack('N', StunMessage::MAGIC_COOKIE));
        };
        [$peerIp, $peerPort] = [substr($peer, 0, strrpos($peer, ':')), (int) substr($peer, strrpos($peer, ':') + 1)];

        // Like coturn: the relay, the client's own address (browsers require it) and the lifetime.
        return (new StunMessage($successType, $request->transaction, [
            [StunMessage::ATTR_XOR_RELAYED_ADDRESS, $xor('203.0.113.7', 49170)],
            [StunMessage::ATTR_XOR_MAPPED_ADDRESS, $xor($peerIp, $peerPort)],
            [StunMessage::ATTR_LIFETIME, pack('N', 600)],
            [StunMessage::ATTR_SOFTWARE, 'fake-turn-server'],
        ]))->encode($key, true);
    }

    return (new StunMessage($successType, $request->transaction, [[StunMessage::ATTR_LIFETIME, pack('N', 0)]]))->encode($key, true);
};

$clients = [];
$idleUntil = time() + (int) ($argv[3] ?? 60);
while (time() < $idleUntil) {
    $read = [$udp, $tcp, ...$clients];
    $write = $except = null;
    if (@stream_select($read, $write, $except, 1) < 1) {
        continue;
    }
    foreach ($read as $socket) {
        if ($socket === $udp) {
            $bytes = stream_socket_recvfrom($udp, 2048, 0, $peer);
            if (($response = $answer((string) $bytes, (string) $peer)) !== null) {
                stream_socket_sendto($udp, $response, 0, $peer);
            }
        } elseif ($socket === $tcp) {
            if ($client = @stream_socket_accept($tcp, 1)) {
                $clients[(int) $client] = $client;
            }
        } else {
            $header = fread($socket, 20);
            if ($header === false || strlen($header) < 20) {
                fclose($socket);
                unset($clients[(int) $socket]);

                continue;
            }
            $length = unpack('n', substr($header, 2, 2))[1];
            $body = $length > 0 ? fread($socket, $length) : '';
            if (($response = $answer($header.$body, (string) stream_socket_get_name($socket, true))) !== null) {
                fwrite($socket, $response);
            }
        }
        $idleUntil = time() + (int) ($argv[3] ?? 60);
    }
}

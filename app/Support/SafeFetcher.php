<?php

namespace App\Support;

/**
 * Downloads public web pages and images on behalf of users without exposing
 * the server's own network (SSRF protection):
 *
 * - http/https on the default ports only, no credentials in the URL;
 * - every address the host resolves to must be public, and the connection is
 *   pinned to the checked address (no DNS rebinding), never through a proxy;
 * - redirects are followed one hop at a time and checked the same way;
 * - short timeouts and a hard size limit (counted after decompression).
 */
class SafeFetcher
{
    public const MAX_REDIRECTS = 4;

    private const USER_AGENT = 'Mozilla/5.0 (compatible; One2OneChat-LinkPreview/1.0)';

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * Canonical form of a user-supplied link, or null when it may not be fetched.
     */
    public function normalize(string $url): ?string
    {
        $url = trim($url);

        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F]/', $url)) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;

        if (! in_array($scheme, ['http', 'https'], true) || ($port !== null && ! in_array($port, [80, 443], true))) {
            return null;
        }

        $host = $this->asciiHost($parts['host']);

        if ($host === null) {
            return null;
        }

        $defaultPort = $scheme === 'https' ? 443 : 80;
        $portPart = $port !== null && $port !== $defaultPort ? ':'.$port : '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $scheme.'://'.$host.$portPart.($parts['path'] ?? '/').$query;
    }

    /**
     * GET a resource whose Content-Type starts with one of $types.
     * With $allowPartial the first $maxBytes are returned for larger bodies
     * (enough for a page's <head>); otherwise larger bodies are refused.
     *
     * @param  list<string>  $types
     * @return array{url:string, content_type:string, body:string}|null
     */
    public function get(string $url, array $types, int $maxBytes, bool $allowPartial = false, int $timeoutSeconds = 5): ?array
    {
        $url = $this->normalize($url);

        for ($hop = 0; $url !== null && $hop <= self::MAX_REDIRECTS; $hop++) {
            $parts = parse_url($url);
            $host = trim((string) $parts['host'], '[]');
            $ip = $this->publicAddress($host);

            if ($ip === null) {
                return null;
            }

            $port = (int) ($parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80));
            $response = $this->transfer($url, $host, $port, $ip, $maxBytes, $allowPartial, $timeoutSeconds);

            if ($response === null) {
                return null;
            }

            if ($response['status'] >= 300 && $response['status'] < 400) {
                $location = $response['headers']['location'] ?? '';
                $url = $location !== '' ? $this->normalize($this->absoluteUrl($url, $location)) : null;

                continue;
            }

            $contentType = strtolower($response['headers']['content-type'] ?? '');
            $mime = trim(explode(';', $contentType)[0]);

            if ($response['status'] !== 200 || ! $this->acceptsType($mime, $types)) {
                return null;
            }

            return ['url' => $url, 'content_type' => $contentType, 'body' => $response['body']];
        }

        return null;
    }

    /**
     * Resolve a link found in a page (relative, protocol-relative or absolute).
     */
    public function absoluteUrl(string $base, string $relative): string
    {
        $relative = trim($relative);

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $relative)) {
            return $relative;
        }

        $parts = parse_url($base);
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($relative, '//')) {
            return $parts['scheme'].':'.$relative;
        }

        if (str_starts_with($relative, '/')) {
            return $origin.$relative;
        }

        if ($relative === '' || str_starts_with($relative, '?') || str_starts_with($relative, '#')) {
            return $origin.($parts['path'] ?? '/').(str_starts_with($relative, '?') ? $relative : (isset($parts['query']) ? '?'.$parts['query'] : ''));
        }

        $directory = preg_replace('#/[^/]*$#', '/', $parts['path'] ?? '/');

        return $origin.$directory.$relative;
    }

    /**
     * Public unicast address? Rejects private, loopback, link-local, carrier-grade
     * NAT, multicast and reserved ranges, including IPv4 embedded in IPv6.
     */
    public static function isPublicIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        $binary = inet_pton($ip);

        if ($binary === false) {
            return false;
        }

        if (strlen($binary) === 4) {
            $long = (int) sprintf('%u', ip2long($ip));

            foreach ([
                [0x64400000, 0xFFC00000], // 100.64.0.0/10 carrier-grade NAT
                [0xC0000000, 0xFFFFFF00], // 192.0.0.0/24 IETF protocol assignments
                [0xC6120000, 0xFFFE0000], // 198.18.0.0/15 benchmarking
                [0xE0000000, 0xF0000000], // 224.0.0.0/4 multicast
                [0x00000000, 0xFF000000], // 0.0.0.0/8
            ] as [$network, $mask]) {
                if (($long & $mask) === $network) {
                    return false;
                }
            }

            return true;
        }

        $embedded = match (true) {
            str_starts_with($binary, str_repeat("\0", 10)."\xFF\xFF") => substr($binary, 12, 4),  // ::ffff:a.b.c.d
            str_starts_with($binary, "\x00\x64\xFF\x9B") => substr($binary, 12, 4),               // 64:ff9b::/96 NAT64
            str_starts_with($binary, "\x20\x02") => substr($binary, 2, 4),                         // 2002::/16 6to4
            str_starts_with($binary, str_repeat("\0", 12)) => substr($binary, 12, 4),             // ::a.b.c.d
            default => null,
        };

        if ($embedded !== null) {
            return self::isPublicIp((string) inet_ntop($embedded));
        }

        $first = ord($binary[0]);
        $second = ord($binary[1]);

        return ! (($first & 0xFE) === 0xFC                 // fc00::/7 unique local
            || ($first === 0xFE && ($second & 0xC0) === 0x80) // fe80::/10 link local
            || $first === 0xFF                                // ff00::/8 multicast
            || str_starts_with($binary, "\x20\x01\x0D\xB8")); // 2001:db8::/32 documentation
    }

    /**
     * The address to connect to: only when every resolved address is public.
     */
    private function publicAddress(string $host): ?string
    {
        $addresses = $this->resolver->resolve($host);

        if ($addresses === []) {
            return null;
        }

        foreach ($addresses as $address) {
            if (! self::isPublicIp($address)) {
                return null;
            }
        }

        // Prefer IPv4: IPv6 routes are missing on many servers.
        usort($addresses, fn ($a, $b) => str_contains($a, ':') <=> str_contains($b, ':'));

        return $addresses[0];
    }

    /**
     * @param  list<string>  $types
     */
    private function acceptsType(string $mime, array $types): bool
    {
        foreach ($types as $type) {
            if ($mime === $type || (str_ends_with($type, '/') && str_starts_with($mime, $type))) {
                return true;
            }
        }

        return false;
    }

    private function asciiHost(string $host): ?string
    {
        $host = strtolower(rtrim($host, '.'));

        if (str_starts_with($host, '[')) {
            $ip = trim($host, '[]');

            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$ip}]" : null;
        }

        if (preg_match('/[^\x21-\x7E]/', $host)) {
            $host = function_exists('idn_to_ascii') ? (idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: '') : '';
        }

        if ($host === '' || strlen($host) > 253 || ! preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host)) {
            return null;
        }

        // Numeric hosts in shorthand, octal or hex forms ("127.1", "0x7f.0.0.1") must be plain IPv4.
        if (preg_match('/^(0x[0-9a-f]*|\d+)(\.(0x[0-9a-f]*|\d+))*$/', $host) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }

        if (! str_contains($host, '.') || preg_match('/(^|\.)(localhost|local|internal|localdomain|home|lan)$/', $host)) {
            return null;
        }

        return $host;
    }

    /**
     * One HTTP GET pinned to $ip, without following redirects.
     *
     * @return array{status:int, headers:array<string,string>, body:string}|null
     */
    protected function transfer(string $url, string $host, int $port, string $ip, int $maxBytes, bool $allowPartial, int $timeoutSeconds): ?array
    {
        if (! function_exists('curl_init')) {
            return null;
        }

        $headers = [];
        $body = '';
        $tooLarge = false;
        $handle = curl_init($url);

        curl_setopt_array($handle, [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOPROXY => '*',
            CURLOPT_RESOLVE => filter_var($host, FILTER_VALIDATE_IP) ? [] : [$host.':'.$port.':'.(str_contains($ip, ':') ? "[{$ip}]" : $ip)],
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => [
                'User-Agent: '.self::USER_AGENT,
                'Accept: text/html,application/xhtml+xml,image/avif,image/webp,image/*;q=0.8,*/*;q=0.5',
                'Accept-Language: en,*;q=0.5',
            ],
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$headers) {
                if (str_starts_with($line, 'HTTP/')) {
                    $headers = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$body, &$tooLarge, $maxBytes) {
                $body .= $chunk;

                if (strlen($body) > $maxBytes) {
                    $tooLarge = true;

                    return -1; // abort the download
                }

                return strlen($chunk);
            },
        ]);

        curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $failed = curl_errno($handle) !== 0;
        unset($handle);

        if ($tooLarge) {
            return $allowPartial ? ['status' => $status, 'headers' => $headers, 'body' => substr($body, 0, $maxBytes)] : null;
        }

        return $failed || $status === 0 ? null : ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
}

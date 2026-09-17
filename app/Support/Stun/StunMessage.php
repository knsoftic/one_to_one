<?php

namespace App\Support\Stun;

/**
 * STUN/TURN messages (RFC 5389 / RFC 5766): enough to ask a TURN server for a relay address
 * with credentials, the way a phone does at the start of a call.
 */
final class StunMessage
{
    public const MAGIC_COOKIE = 0x2112A442;

    public const BINDING_REQUEST = 0x0001;

    public const BINDING_SUCCESS = 0x0101;

    public const ALLOCATE_REQUEST = 0x0003;

    public const ALLOCATE_SUCCESS = 0x0103;

    public const ALLOCATE_ERROR = 0x0113;

    public const REFRESH_REQUEST = 0x0004;

    public const ATTR_MAPPED_ADDRESS = 0x0001;

    public const ATTR_USERNAME = 0x0006;

    public const ATTR_MESSAGE_INTEGRITY = 0x0008;

    public const ATTR_ERROR_CODE = 0x0009;

    public const ATTR_LIFETIME = 0x000D;

    public const ATTR_REALM = 0x0014;

    public const ATTR_NONCE = 0x0015;

    public const ATTR_XOR_RELAYED_ADDRESS = 0x0016;

    public const ATTR_REQUESTED_TRANSPORT = 0x0019;

    public const ATTR_XOR_MAPPED_ADDRESS = 0x0020;

    public const ATTR_SOFTWARE = 0x8022;

    public const ATTR_FINGERPRINT = 0x8028;

    private const FINGERPRINT_XOR = 0x5354554E;

    /**
     * @param  list<array{0: int, 1: string}>  $attributes  [type, value] in order
     */
    public function __construct(
        public readonly int $type,
        public readonly string $transaction,
        public readonly array $attributes = [],
    ) {}

    public static function transactionId(): string
    {
        return random_bytes(12);
    }

    /** Long-term credential key (RFC 5389 §15.4). */
    public static function longTermKey(string $username, string $realm, string $password): string
    {
        return md5($username.':'.$realm.':'.$password, true);
    }

    /**
     * The bytes to send, signed with MESSAGE-INTEGRITY when a key is given and ending with FINGERPRINT.
     */
    public function encode(?string $integrityKey = null, bool $fingerprint = false): string
    {
        $body = '';
        foreach ($this->attributes as [$type, $value]) {
            $body .= self::attribute($type, $value);
        }

        if ($integrityKey !== null) {
            // The length in the signed header already counts the MESSAGE-INTEGRITY attribute.
            $signed = $this->header(strlen($body) + 24).$body;
            $body .= self::attribute(self::ATTR_MESSAGE_INTEGRITY, hash_hmac('sha1', $signed, $integrityKey, true));
        }

        if ($fingerprint) {
            $crc = crc32($this->header(strlen($body) + 8).$body) ^ self::FINGERPRINT_XOR;
            $body .= self::attribute(self::ATTR_FINGERPRINT, pack('N', $crc & 0xFFFFFFFF));
        }

        return $this->header(strlen($body)).$body;
    }

    /** Reads a message; null when the bytes are not a whole STUN message. */
    public static function decode(string $bytes): ?self
    {
        if (strlen($bytes) < 20) {
            return null;
        }
        ['type' => $type, 'length' => $length, 'cookie' => $cookie] = unpack('ntype/nlength/Ncookie', $bytes);
        if (($type & 0xC000) !== 0 || $cookie !== self::MAGIC_COOKIE || $length % 4 !== 0 || strlen($bytes) < 20 + $length) {
            return null;
        }

        $attributes = [];
        $offset = 20;
        $end = 20 + $length;
        while ($offset + 4 <= $end) {
            ['type' => $attrType, 'length' => $attrLength] = unpack('ntype/nlength', substr($bytes, $offset, 4));
            if ($offset + 4 + $attrLength > $end) {
                return null;
            }
            $attributes[] = [$attrType, substr($bytes, $offset + 4, $attrLength), $offset];
            $offset += 4 + $attrLength + ((4 - $attrLength % 4) % 4);
        }

        return new self($type, substr($bytes, 8, 12), array_map(fn ($a) => [$a[0], $a[1]], $attributes));
    }

    /** Whether the MESSAGE-INTEGRITY of received bytes matches the key. */
    public static function hasValidIntegrity(string $bytes, string $key): bool
    {
        $message = self::decode($bytes);
        if ($message === null) {
            return false;
        }

        $offset = 20;
        foreach ($message->attributes as [$type, $value]) {
            if ($type === self::ATTR_MESSAGE_INTEGRITY) {
                $signed = pack('nn', $message->type, $offset - 20 + 24).substr($bytes, 4, $offset - 4);

                return strlen($value) === 20 && hash_equals(hash_hmac('sha1', $signed, $key, true), $value);
            }
            $offset += 4 + strlen($value) + ((4 - strlen($value) % 4) % 4);
        }

        return false;
    }

    public function get(int $type): ?string
    {
        foreach ($this->attributes as [$attrType, $value]) {
            if ($attrType === $type) {
                return $value;
            }
        }

        return null;
    }

    /** @return array{code: int, reason: string}|null */
    public function errorCode(): ?array
    {
        $value = $this->get(self::ATTR_ERROR_CODE);
        if ($value === null || strlen($value) < 4) {
            return null;
        }

        return ['code' => (ord($value[2]) & 0x07) * 100 + ord($value[3]), 'reason' => trim(substr($value, 4))];
    }

    /** An XOR-…-ADDRESS attribute as "ip:port". */
    public function xorAddress(int $attribute): ?string
    {
        $value = $this->get($attribute);
        if ($value === null || strlen($value) < 8) {
            return null;
        }

        $family = ord($value[1]);
        $port = unpack('n', substr($value, 2, 2))[1] ^ (self::MAGIC_COOKIE >> 16);
        $mask = pack('N', self::MAGIC_COOKIE).$this->transaction;

        if ($family === 0x01) {
            $ip = inet_ntop(substr($value, 4, 4) ^ substr($mask, 0, 4));

            return $ip.':'.$port;
        }
        if ($family === 0x02 && strlen($value) >= 20) {
            $ip = inet_ntop(substr($value, 4, 16) ^ $mask);

            return '['.$ip.']:'.$port;
        }

        return null;
    }

    private function header(int $length): string
    {
        return pack('nnN', $this->type, $length, self::MAGIC_COOKIE).$this->transaction;
    }

    private static function attribute(int $type, string $value): string
    {
        return pack('nn', $type, strlen($value)).$value.str_repeat("\0", (4 - strlen($value) % 4) % 4);
    }
}

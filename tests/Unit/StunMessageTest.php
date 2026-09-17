<?php

namespace Tests\Unit;

use App\Support\Stun\StunMessage;
use PHPUnit\Framework\TestCase;

/**
 * STUN/TURN message encoding, checked against the test vectors of RFC 5769.
 */
class StunMessageTest extends TestCase
{
    /** RFC 5769 §2.4: a request with long-term credentials. */
    private const LONG_TERM_REQUEST = '000100602112a44278ad3433c6ad72c029da412e'
        .'00060012e3839ee38388e383aae38383e382afe382b90000'
        .'0015001c662f2f3439396b39353464364f4c33346f4c39465354767936347341'
        .'0014000b6578616d706c652e6f726700'
        .'00080014f67024656dd64a3e02b8e0712e85c9a28ca89666';

    private function rfcKey(): string
    {
        return StunMessage::longTermKey("\u{30DE}\u{30C8}\u{30EA}\u{30C3}\u{30AF}\u{30B9}", 'example.org', 'TheMatrIX');
    }

    public function test_it_signs_a_request_exactly_like_rfc_5769(): void
    {
        $message = new StunMessage(StunMessage::BINDING_REQUEST, hex2bin('78ad3433c6ad72c029da412e'), [
            [StunMessage::ATTR_USERNAME, "\u{30DE}\u{30C8}\u{30EA}\u{30C3}\u{30AF}\u{30B9}"],
            [StunMessage::ATTR_NONCE, 'f//499k954d6OL34oL9FSTvy64sA'],
            [StunMessage::ATTR_REALM, 'example.org'],
        ]);

        $this->assertSame(self::LONG_TERM_REQUEST, bin2hex($message->encode($this->rfcKey())));
    }

    public function test_it_reads_and_verifies_a_signed_message(): void
    {
        $bytes = hex2bin(self::LONG_TERM_REQUEST);
        $message = StunMessage::decode($bytes);

        $this->assertNotNull($message);
        $this->assertSame(StunMessage::BINDING_REQUEST, $message->type);
        $this->assertSame('example.org', $message->get(StunMessage::ATTR_REALM));
        $this->assertSame('f//499k954d6OL34oL9FSTvy64sA', $message->get(StunMessage::ATTR_NONCE));
        $this->assertTrue(StunMessage::hasValidIntegrity($bytes, $this->rfcKey()));
        $this->assertFalse(StunMessage::hasValidIntegrity($bytes, StunMessage::longTermKey('someone', 'example.org', 'TheMatrIX')));

        // One changed byte breaks the signature.
        $tampered = $bytes;
        $tampered[60] = 'X';
        $this->assertFalse(StunMessage::hasValidIntegrity($tampered, $this->rfcKey()));
    }

    public function test_it_reads_an_xor_address_like_rfc_5769(): void
    {
        // RFC 5769 §2.2: XOR-MAPPED-ADDRESS 192.0.2.1:32853.
        $message = new StunMessage(StunMessage::BINDING_SUCCESS, hex2bin('b7e7a701bc34d686fa87dfae'), [
            [StunMessage::ATTR_XOR_MAPPED_ADDRESS, hex2bin('0001a147e112a643')],
        ]);

        $this->assertSame('192.0.2.1:32853', $message->xorAddress(StunMessage::ATTR_XOR_MAPPED_ADDRESS));
        $this->assertNull($message->xorAddress(StunMessage::ATTR_XOR_RELAYED_ADDRESS));
    }

    public function test_error_codes_padding_fingerprint_and_garbage(): void
    {
        $message = new StunMessage(StunMessage::ALLOCATE_ERROR, str_repeat("\x01", 12), [
            [StunMessage::ATTR_ERROR_CODE, pack('nCC', 0, 4, 38).'Stale Nonce'],
            [StunMessage::ATTR_NONCE, 'abc'],
        ]);
        $bytes = $message->encode(null, true);

        $this->assertSame(0, (strlen($bytes) - 20) % 4);
        $decoded = StunMessage::decode($bytes);
        $this->assertSame(['code' => 438, 'reason' => 'Stale Nonce'], $decoded->errorCode());
        $this->assertSame('abc', $decoded->get(StunMessage::ATTR_NONCE));
        $this->assertNotNull($decoded->get(StunMessage::ATTR_FINGERPRINT));
        $crc = (crc32(substr($bytes, 0, -8)) ^ 0x5354554E) & 0xFFFFFFFF;
        $this->assertSame(pack('N', $crc), $decoded->get(StunMessage::ATTR_FINGERPRINT));

        $this->assertNull(StunMessage::decode('HTTP/1.1 400 Bad Request'));
        $this->assertNull(StunMessage::decode(substr($bytes, 0, -3)));
    }
}

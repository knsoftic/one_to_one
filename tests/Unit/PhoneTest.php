<?php

namespace Tests\Unit;

use App\Support\Phone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneTest extends TestCase
{
    public static function samePhones(): array
    {
        return [
            'local with trunk zero' => ['0300 1234567', '+923001234567'],
            'international with plus' => ['+92 300 123-4567', '+923001234567'],
            'international with 00' => ['0092 300 1234567', '+923001234567'],
            'national without zero' => ['3001234567', '+923001234567'],
            'brackets and dashes' => ['(0300) 123-4567', '+923001234567'],
        ];
    }

    #[DataProvider('samePhones')]
    public function test_matches_the_same_number_in_different_formats(string $phoneBook, string $stored): void
    {
        $this->assertTrue(Phone::matches($phoneBook, $stored));
        $this->assertSame(Phone::suffix($stored), Phone::suffix($phoneBook));
    }

    public function test_does_not_match_different_numbers(): void
    {
        $this->assertFalse(Phone::matches('0300 1234568', '+923001234567'));
        $this->assertFalse(Phone::matches('+1 300 123 4567', '+923001234567'));
        $this->assertFalse(Phone::matches('12345', '+923001234567'));
    }

    public function test_suffix_is_null_for_short_numbers(): void
    {
        $this->assertNull(Phone::suffix('1122'));
        $this->assertSame('001234567', Phone::suffix('+92 300 1234567'));
    }
}

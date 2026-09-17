<?php

namespace Tests\Unit;

use App\Support\DialCode;
use PHPUnit\Framework\TestCase;

class DialCodeTest extends TestCase
{
    public function test_it_reads_the_country_from_an_international_number(): void
    {
        $this->assertSame('PK', DialCode::country('+923001110001'));
        $this->assertSame('US', DialCode::country('+14155551234'));
        $this->assertSame('GB', DialCode::country('+442071234567'));
        $this->assertSame('SA', DialCode::country('+966500000000'));
        $this->assertSame('AE', DialCode::country('+971501234567'));
        // "00" international prefix and spacing.
        $this->assertSame('PK', DialCode::country('0092 300 1110001'));
        $this->assertNull(DialCode::country(''));
        $this->assertNull(DialCode::country('+9991234')); // 999 is not a real country code
    }

    public function test_the_country_list_is_named_and_sorted(): void
    {
        $countries = DialCode::countries();
        $this->assertSame('Pakistan', $countries['PK']);
        $this->assertSame('United States', $countries['US']);
        $this->assertSame(array_values($countries), array_values(collect($countries)->sort()->all()));
    }
}

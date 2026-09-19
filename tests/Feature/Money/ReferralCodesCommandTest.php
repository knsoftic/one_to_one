<?php

namespace Tests\Feature\Money;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `chat:referral-codes` backfills the code of every account that does not have one (Y2).
 */
class ReferralCodesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_gives_every_account_a_code_without_skipping_any(): void
    {
        // More than one batch, so an offset-based chunk over the shrinking result set would
        // step straight over the middle of the list.
        User::factory()->count(12)->create();
        $already = User::query()->first();
        $already->forceFill(['referral_code' => 'AAAA2345'])->save();

        $this->artisan('chat:referral-codes')->assertSuccessful();

        $this->assertSame(0, User::query()->whereNull('referral_code')->count());
        $this->assertSame(12, User::query()->distinct()->count('referral_code'));
        $this->assertSame('AAAA2345', $already->fresh()->referral_code);
    }
}

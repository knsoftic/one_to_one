<?php

namespace Tests\Feature\Chat;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S5 — Mute someone's status: their updates move down to "Muted updates".
 */
class StatusMuteTest extends TestCase
{
    use RefreshDatabase, StatusTestHelpers;

    public function test_muted_updates_are_marked_and_can_be_unmuted(): void
    {
        [$me, $ayesha] = User::factory()->count(2)->create();
        $this->saveContact($ayesha, $me);
        $this->textStatus($ayesha);

        $this->actingAs($me)->postJson("/statuses/mutes/{$ayesha->id}")->assertOk()->assertJsonPath('muted', true);
        $this->actingAs($me)->postJson("/statuses/mutes/{$ayesha->id}")->assertOk();
        $this->actingAs($me)->getJson('/statuses')
            ->assertJsonCount(1, 'updates')
            ->assertJsonPath('updates.0.muted', true)
            ->assertJsonCount(1, 'updates.0.statuses');

        // Ayesha never finds out and her feed is unchanged.
        $this->actingAs($ayesha)->getJson('/statuses')->assertJsonCount(1, 'mine');

        $this->actingAs($me)->deleteJson("/statuses/mutes/{$ayesha->id}")->assertOk()->assertJsonPath('muted', false);
        $this->actingAs($me)->getJson('/statuses')->assertJsonPath('updates.0.muted', false);

        $this->actingAs($me)->postJson("/statuses/mutes/{$me->id}")->assertUnprocessable();
    }
}

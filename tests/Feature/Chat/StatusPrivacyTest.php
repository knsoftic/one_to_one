<?php

namespace Tests\Feature\Chat;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S3 — Status privacy: my contacts / my contacts except… / only share with…
 */
class StatusPrivacyTest extends TestCase
{
    use RefreshDatabase, StatusTestHelpers;

    public function test_privacy_decides_who_sees_new_updates(): void
    {
        [$ayesha, $bilal, $sara, $hina] = User::factory()->count(4)->create();
        $this->saveContact($ayesha, $bilal);
        $this->saveContact($ayesha, $sara);

        $this->actingAs($ayesha)->getJson('/status/privacy')->assertExactJson(['mode' => 'contacts', 'except_ids' => [], 'only_ids' => []]);
        $before = $this->textStatus($ayesha, 'for everyone');

        // My contacts except Bilal: updates from now on skip him; the earlier one stays.
        $this->actingAs($ayesha)->putJson('/status/privacy', ['mode' => 'except', 'except_ids' => [$bilal->id, $ayesha->id]])
            ->assertOk()->assertJsonPath('mode', 'except')->assertJsonPath('except_ids', [$bilal->id]);
        $except = $this->textStatus($ayesha, 'not for Bilal');
        $this->assertSame([$before], $this->visibleIds($bilal));
        $this->assertSame([$before, $except], $this->visibleIds($sara));
        $this->actingAs($bilal)->postJson("/statuses/{$except}/view")->assertNotFound();

        // Only share with Hina (not a contact): nobody else sees it.
        $this->actingAs($ayesha)->putJson('/status/privacy', ['mode' => 'only', 'only_ids' => [$hina->id]])
            ->assertOk()->assertJsonPath('only_ids', [$hina->id])->assertJsonPath('except_ids', [$bilal->id]);
        $only = $this->textStatus($ayesha, 'just Hina');
        $this->assertSame([$only], $this->visibleIds($hina));
        $this->assertSame([$before, $except], $this->visibleIds($sara));
        $this->actingAs($ayesha)->getJson('/statuses')->assertJsonPath('mine.2.privacy', 'only');

        // "Only share with" needs someone.
        $this->actingAs($ayesha)->putJson('/status/privacy', ['mode' => 'only', 'only_ids' => []])
            ->assertUnprocessable()->assertJsonPath('message', 'Choose at least one person to share your status with.');
        $this->actingAs($ayesha)->getJson('/status/privacy')->assertJsonPath('mode', 'contacts');
        $this->actingAs($ayesha)->putJson('/status/privacy', ['mode' => 'nobody'])->assertJsonValidationErrors('mode');
    }

    /**
     * @return list<int>
     */
    private function visibleIds(User $viewer): array
    {
        return collect($this->actingAs($viewer)->getJson('/statuses')->json('updates.0.statuses') ?? [])->pluck('id')->all();
    }
}

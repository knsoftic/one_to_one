<?php

namespace Tests\Feature\Chat;

use App\Events\StatusViewed;
use App\Models\StatusView;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * S2 — Who saw my status.
 */
class StatusViewersTest extends TestCase
{
    use RefreshDatabase, StatusTestHelpers;

    public function test_the_owner_sees_who_viewed_an_update_and_when(): void
    {
        Event::fake([StatusViewed::class]);
        [$ayesha, $bilal, $sara, $stranger] = User::factory()->count(4)->sequence(['name' => 'Ayesha'], ['name' => 'Bilal'], ['name' => 'Sara'], ['name' => 'Hina'])->create();
        $this->saveContact($ayesha, $bilal);
        $this->saveContact($ayesha, $sara);
        $id = $this->textStatus($ayesha);

        $this->actingAs($bilal)->postJson("/statuses/{$id}/view")->assertOk();
        $this->travel(2)->minutes();
        $this->actingAs($bilal)->postJson("/statuses/{$id}/view")->assertOk();
        $this->actingAs($sara)->postJson("/statuses/{$id}/view")->assertOk();
        // The owner's own views and strangers don't count.
        $this->actingAs($ayesha)->postJson("/statuses/{$id}/view")->assertOk();
        $this->actingAs($stranger)->postJson("/statuses/{$id}/view")->assertNotFound();

        $this->assertSame(2, StatusView::count());
        Event::assertDispatchedTimes(StatusViewed::class, 2);
        Event::assertDispatched(StatusViewed::class, fn (StatusViewed $event) => $event->ownerId === $ayesha->id && $event->viewsCount === 2);

        $this->actingAs($ayesha)->getJson("/statuses/{$id}/viewers")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.user.name', 'Sara')
            ->assertJsonPath('data.1.user.name', 'Bilal')
            ->assertJsonPath('data.1.reaction', null);
        $this->actingAs($bilal)->getJson("/statuses/{$id}/viewers")->assertNotFound();

        $this->actingAs($ayesha)->getJson('/statuses')->assertJsonPath('mine.0.views_count', 2);
        $this->actingAs($bilal)->getJson('/statuses')
            ->assertJsonPath('updates.0.viewed', true)
            ->assertJsonPath('updates.0.statuses.0.viewed', true);
    }

    public function test_people_not_seen_yet_come_first(): void
    {
        [$me, $ayesha, $bilal] = User::factory()->count(3)->create();
        $this->saveContact($ayesha, $me);
        $this->saveContact($bilal, $me);

        $old = $this->textStatus($ayesha);
        $this->travel(5)->minutes();
        $this->textStatus($bilal);
        $this->actingAs($me)->getJson('/statuses')->assertJsonPath('updates.0.user.id', $bilal->id);

        $this->actingAs($me)->postJson("/statuses/{$this->textStatus($bilal, 'second')}/view");
        $this->actingAs($me)->getJson('/statuses')->assertJsonPath('updates.0.user.id', $bilal->id)->assertJsonPath('updates.0.viewed', false);

        foreach ($this->actingAs($me)->getJson('/statuses')->json('updates.0.statuses') as $status) {
            $this->actingAs($me)->postJson("/statuses/{$status['id']}/view");
        }
        $this->actingAs($me)->getJson('/statuses')
            ->assertJsonPath('updates.0.user.id', $ayesha->id)
            ->assertJsonPath('updates.0.statuses.0.id', $old)
            ->assertJsonPath('updates.1.viewed', true);
    }
}

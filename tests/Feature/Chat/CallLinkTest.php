<?php

namespace Tests\Feature\Chat;

use App\Events\CallStarted;
use App\Models\BlockedUser;
use App\Models\Call;
use App\Models\CallLink;
use App\Models\CallRoom;
use App\Models\CallRoomParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * K7 — Call links: people with the link join a call with its owner.
 */
class CallLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['name' => 'Ayesha']);
    }

    public function test_links_are_created_listed_and_deleted_by_their_owner(): void
    {
        $link = $this->actingAs($this->owner)->postJson('/call-links', ['type' => 'video'])
            ->assertCreated()
            ->assertJsonPath('type', 'video')
            ->assertJsonPath('is_mine', true)
            ->json();

        $this->assertStringContainsString('/call/'.$link['token'], $link['url']);
        $this->actingAs($this->owner)->getJson('/call-links')->assertJsonCount(1, 'data');

        $this->actingAs(User::factory()->create())->deleteJson("/call-links/{$link['token']}")->assertNotFound();
        $this->actingAs($this->owner)->deleteJson("/call-links/{$link['token']}")->assertOk();
        $this->actingAs($this->owner)->getJson('/call-links')->assertJsonCount(0, 'data');

        // Opening a deleted link says so.
        $this->actingAs(User::factory()->create())->get("/call/{$link['token']}")
            ->assertOk()
            ->assertViewHas('chatConfig', fn (array $config) => $config['callLink']['valid'] === false);
        $this->actingAs(User::factory()->create())->postJson("/call-links/{$link['token']}/join", ['client_id' => 'guest-tab-01'])->assertStatus(410);
    }

    public function test_people_who_are_not_signed_in_sign_in_first(): void
    {
        $link = CallLink::create(['user_id' => $this->owner->id, 'token' => str_repeat('a', 24), 'type' => 'audio']);

        $this->get("/call/{$link->token}")->assertRedirect(route('login'));
    }

    public function test_opening_a_link_starts_the_call_and_rings_the_owner(): void
    {
        $token = $this->link('audio');
        $bilal = User::factory()->create(['name' => 'Bilal']);
        Event::fake([CallStarted::class]);

        $this->actingAs($bilal)->get("/call/{$token}")
            ->assertOk()
            ->assertViewHas('chatConfig', fn (array $config) => $config['callLink']['owner']['name'] === 'Ayesha' && $config['callLink']['type'] === 'audio');

        $this->actingAs($bilal)->postJson("/call-links/{$token}/join", ['client_id' => 'bilal-tab-01'])
            ->assertOk()
            ->assertJsonPath('room.from_link', true)
            ->assertJsonPath('room.participants.0.user_id', $bilal->id)
            ->assertJsonPath('room.participants.0.status', 'joined')
            ->assertJsonPath('room.participants.1.user_id', $this->owner->id)
            ->assertJsonPath('room.participants.1.status', 'ringing');

        Event::assertDispatched(CallStarted::class, fn (CallStarted $event) => $event->call->callee_id === $this->owner->id);

        // Sara opens the same link: she joins the same call.
        $sara = User::factory()->create();
        $this->actingAs($sara)->postJson("/call-links/{$token}/join", ['client_id' => 'sara-tab-01'])
            ->assertOk()
            ->assertJsonCount(3, 'room.participants');
        $this->assertSame(1, CallRoom::count());

        // Joining again from the same device changes nothing.
        $this->actingAs($sara)->postJson("/call-links/{$token}/join", ['client_id' => 'sara-tab-01'])->assertOk();
        $this->assertSame(3, CallRoomParticipant::count());
    }

    public function test_the_owner_can_open_their_own_link_and_wait_for_others(): void
    {
        $token = $this->link('video');

        $room = $this->actingAs($this->owner)->postJson("/call-links/{$token}/join", ['client_id' => 'owner-tab-01'])
            ->assertOk()
            ->assertJsonCount(1, 'room.participants')
            ->json('room');
        $this->assertSame(0, Call::count());

        // Someone joins, then leaves: the owner keeps waiting (the call came from a link).
        $bilal = User::factory()->create();
        $this->actingAs($bilal)->postJson("/call-links/{$token}/join", ['client_id' => 'bilal-tab-01'])->assertOk();
        $this->actingAs($bilal)->postJson("/call-rooms/{$room['id']}/leave")->assertOk()->assertJsonPath('room.status', 'active');

        // When the owner leaves too, the call ends; the link can start a new one later.
        $this->actingAs($this->owner)->postJson("/call-rooms/{$room['id']}/leave")->assertOk()->assertJsonPath('room.status', 'ended');
        $this->actingAs($bilal)->postJson("/call-links/{$token}/join", ['client_id' => 'bilal-tab-01'])->assertOk();
        $this->assertSame(2, CallRoom::count());
    }

    public function test_blocked_people_busy_people_and_full_calls_cannot_join(): void
    {
        $token = $this->link('audio');

        $blocked = User::factory()->create();
        BlockedUser::create(['user_id' => $this->owner->id, 'blocked_user_id' => $blocked->id]);
        $this->actingAs($blocked)->postJson("/call-links/{$token}/join", ['client_id' => 'blocked-tab-01'])->assertForbidden();

        [$first, $second, $third] = User::factory()->count(3)->create();
        foreach ([$first, $second, $third] as $i => $user) {
            $this->actingAs($user)->postJson("/call-links/{$token}/join", ['client_id' => "user-tab-0{$i}"])->assertOk();
        }
        // Three people + the owner ringing = 4.
        $this->actingAs(User::factory()->create())->postJson("/call-links/{$token}/join", ['client_id' => 'late-tab-01'])
            ->assertUnprocessable()->assertJsonPath('message', 'The call is full.');

        // Someone already in another call.
        $other = CallLink::create(['user_id' => User::factory()->create()->id, 'token' => str_repeat('b', 24), 'type' => 'audio']);
        $this->actingAs($first)->postJson("/call-links/{$other->token}/join", ['client_id' => 'user-tab-00'])
            ->assertConflict()->assertJsonPath('message', 'You are already in a call.');
    }

    private function link(string $type): string
    {
        return $this->actingAs($this->owner)->postJson('/call-links', ['type' => $type])->assertCreated()->json('token');
    }
}

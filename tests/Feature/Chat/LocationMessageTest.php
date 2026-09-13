<?php

namespace Tests\Feature\Chat;

use App\Events\MessageUpdated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * M18 — Share current location and live location.
 */
class LocationMessageTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $friend;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->me, $this->friend] = User::factory()->count(2)->create();
        $this->conversation = Conversation::factory()->between($this->me, $this->friend)->create();
    }

    public function test_the_current_location_is_sent_rounded_and_shown_to_both(): void
    {
        $this->send(['location' => ['lat' => 31.5203696123, 'lng' => 74.3587473999, 'accuracy' => 12.6]])
            ->assertCreated()
            ->assertJsonPath('type', 'location')
            ->assertJsonPath('location.lat', 31.52037)
            ->assertJsonPath('location.lng', 74.358747)
            ->assertJsonPath('location.accuracy', 13)
            ->assertJsonPath('location.live', false)
            ->assertJsonPath('location.live_active', false);

        $this->assertSame('📍 Location', Message::sole()->preview());

        $this->actingAs($this->friend)->getJson("/conversations/{$this->conversation->id}/messages")
            ->assertJsonPath('data.0.location.lat', 31.52037);
    }

    public function test_invalid_locations_and_durations_are_refused(): void
    {
        $this->send(['location' => ['lat' => 91, 'lng' => 10]])->assertJsonValidationErrors('location.lat');
        $this->send(['location' => ['lat' => 10, 'lng' => -181]])->assertJsonValidationErrors('location.lng');
        $this->send(['location' => ['lat' => 10]])->assertJsonValidationErrors('location.lng');
        $this->send(['location' => ['lat' => 10, 'lng' => 10, 'live_minutes' => 30]])->assertJsonValidationErrors('location.live_minutes');
        $this->send(['location' => ['lat' => 10, 'lng' => 10, 'owner' => 'x']])->assertJsonValidationErrors('location');

        $this->assertSame(0, Message::count());
    }

    public function test_live_location_updates_are_broadcast_until_stopped(): void
    {
        Event::fake([MessageUpdated::class]);

        $id = $this->send(['location' => ['lat' => 24.86, 'lng' => 67.01, 'live_minutes' => 15]])
            ->assertCreated()
            ->assertJsonPath('location.live', true)
            ->assertJsonPath('location.live_active', true)
            ->json('id');

        $this->assertSame('📍 Live location', Message::find($id)->preview());

        $this->travel(20)->seconds();
        $this->actingAs($this->me)->patchJson("/messages/{$id}/location", ['lat' => 24.87, 'lng' => 67.02, 'accuracy' => 8])
            ->assertOk()
            ->assertJsonPath('location.lat', 24.87)
            ->assertJsonPath('location.live_active', true);

        Event::assertDispatched(MessageUpdated::class, fn (MessageUpdated $event) => $event->broadcastWith()['message']['location']['lng'] === 67.02);

        // Only the sender can move it.
        $this->actingAs($this->friend)->patchJson("/messages/{$id}/location", ['lat' => 0, 'lng' => 0])->assertForbidden();

        $this->actingAs($this->me)->deleteJson("/messages/{$id}/location")
            ->assertOk()
            ->assertJsonPath('location.live_active', false);

        $this->actingAs($this->me)->patchJson("/messages/{$id}/location", ['lat' => 1, 'lng' => 1])
            ->assertForbidden()
            ->assertJsonPath('message', 'This live location has ended.');
    }

    public function test_live_location_ends_by_itself_and_forwarding_sends_the_last_point(): void
    {
        $id = $this->send(['location' => ['lat' => 33.68, 'lng' => 73.04, 'live_minutes' => 15]])->json('id');

        $this->travel(16)->minutes();

        $this->actingAs($this->friend)->getJson("/conversations/{$this->conversation->id}/messages")
            ->assertJsonPath('data.0.location.live', true)
            ->assertJsonPath('data.0.location.live_active', false);
        $this->actingAs($this->me)->patchJson("/messages/{$id}/location", ['lat' => 1, 'lng' => 1])->assertForbidden();

        $other = Conversation::factory()->between($this->me, User::factory()->create())->create();
        $this->actingAs($this->me)->postJson("/messages/{$id}/forward", ['conversation_ids' => [$other->id]])
            ->assertCreated()
            ->assertJsonPath('data.0.type', 'location')
            ->assertJsonPath('data.0.location.lat', 33.68)
            ->assertJsonPath('data.0.location.live', false);
    }

    public function test_pages_allow_location_access_for_this_site(): void
    {
        $this->actingAs($this->me)->get('/chat')->assertHeader('Permissions-Policy', 'microphone=(self), camera=(self), geolocation=(self), payment=()');
    }

    private function send(array $payload)
    {
        return $this->actingAs($this->me)->postJson("/conversations/{$this->conversation->id}/messages", $payload);
    }
}

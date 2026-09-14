<?php

namespace Tests\Feature\Chat;

use App\Events\CallRoomUpdated;
use App\Events\CallSignalSent;
use App\Events\CallStarted;
use App\Models\BlockedUser;
use App\Models\Call;
use App\Models\CallRoom;
use App\Models\CallRoomParticipant;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * K6 — Group calls (up to 4 people, mesh).
 */
class GroupCallTest extends TestCase
{
    use RefreshDatabase;

    private User $ayesha;

    private User $bilal;

    private User $sara;

    private User $hina;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->ayesha, $this->bilal, $this->sara, $this->hina] = User::factory()->count(4)->create();
    }

    public function test_adding_someone_to_a_call_turns_it_into_a_group_call_that_rings_them(): void
    {
        $call = $this->answeredCall($this->ayesha, $this->bilal);
        Event::fake([CallStarted::class, CallRoomUpdated::class]);

        $response = $this->actingAs($this->bilal)->postJson("/calls/{$call->id}/participants", ['user_id' => $this->sara->id])
            ->assertCreated()
            ->assertJsonPath('room.status', 'active')
            ->assertJsonPath('room.participants.0.user_id', $this->ayesha->id)
            ->assertJsonPath('room.participants.0.status', 'joined')
            ->assertJsonPath('room.participants.1.user_id', $this->bilal->id)
            ->assertJsonPath('room.participants.2.user_id', $this->sara->id)
            ->assertJsonPath('room.participants.2.status', 'ringing')
            ->assertJsonPath('invite.callee_id', $this->sara->id)
            ->assertJsonPath('invite.caller_id', $this->bilal->id);

        $roomId = $response->json('room.id');
        $this->assertSame($roomId, $call->fresh()->call_room_id);

        // Sara's phones ring with a normal call that says who is already talking.
        Event::assertDispatched(CallStarted::class, fn (CallStarted $event) => $event->call->callee_id === $this->sara->id
            && $event->broadcastWith()['call']['room']['id'] === $roomId
            && count($event->broadcastWith()['call']['room']['participants']) === 2);
        Event::assertDispatched(CallRoomUpdated::class);

        // She answers: she joins and is the one who connects to the others (latest join_seq).
        $invite = Call::query()->where('callee_id', $this->sara->id)->sole();
        $this->actingAs($this->sara)->postJson("/calls/{$invite->id}/accept", ['client_id' => 'sara-phone-01'])->assertOk();

        $this->actingAs($this->sara)->getJson("/call-rooms/{$roomId}")
            ->assertOk()
            ->assertJsonPath('room.participants.2.status', 'joined')
            ->assertJsonPath('room.participants.2.client_id', 'sara-phone-01')
            ->assertJsonPath('room.participants.2.join_seq', 3);
    }

    public function test_group_calls_are_limited_to_four_people_and_respect_blocks_and_busy_people(): void
    {
        $call = $this->answeredCall($this->ayesha, $this->bilal);
        $room = $this->addAndAnswer($call, $this->ayesha, $this->sara);

        $this->actingAs($this->ayesha)->postJson("/call-rooms/{$room->id}/participants", ['user_id' => $this->sara->id])
            ->assertUnprocessable()->assertJsonPath('message', "{$this->sara->name} is already in the call.");

        $blocked = User::factory()->create();
        BlockedUser::create(['user_id' => $blocked->id, 'blocked_user_id' => $this->ayesha->id]);
        $this->actingAs($this->ayesha)->postJson("/call-rooms/{$room->id}/participants", ['user_id' => $blocked->id])->assertForbidden();

        $busy = User::factory()->create();
        $other = User::factory()->create();
        $this->answeredCall($busy, $other);
        $this->actingAs($this->ayesha)->postJson("/call-rooms/{$room->id}/participants", ['user_id' => $busy->id])
            ->assertConflict()->assertJsonPath('message', "{$busy->name} is on another call.");

        $this->actingAs($this->ayesha)->postJson("/call-rooms/{$room->id}/participants", ['user_id' => $this->hina->id])->assertCreated();
        $this->actingAs($this->ayesha)->postJson("/call-rooms/{$room->id}/participants", ['user_id' => User::factory()->create()->id])
            ->assertUnprocessable()->assertJsonPath('message', 'A group call can have up to 4 people.');

        // People in a group call are busy for other calls.
        $caller = User::factory()->create();
        $conversation = Conversation::factory()->between($caller, $this->sara)->create();
        $this->actingAs($caller)->postJson("/conversations/{$conversation->id}/calls", ['type' => 'audio', 'client_id' => 'caller-tab-99'])
            ->assertCreated()->assertJsonPath('call.end_reason', 'busy');

        // Strangers cannot see or add to the room.
        $this->actingAs(User::factory()->create())->getJson("/call-rooms/{$room->id}")->assertNotFound();
    }

    public function test_signals_go_only_to_the_device_they_are_meant_for_in_the_room(): void
    {
        $call = $this->answeredCall($this->ayesha, $this->bilal);
        $room = $this->addAndAnswer($call, $this->ayesha, $this->sara);
        Event::fake([CallSignalSent::class]);

        $offer = json_encode(['type' => 'offer', 'sdp' => 'v=0']);
        $this->actingAs($this->sara)->postJson("/call-rooms/{$room->id}/signals", [
            'client_id' => 'sara-phone-01', 'to_user_id' => $this->ayesha->id, 'to_client' => 'caller-tab-01', 'type' => 'offer', 'payload' => $offer,
        ])->assertCreated();

        Event::assertDispatched(CallSignalSent::class, fn (CallSignalSent $event) => $event->broadcastOn()[0]->name === 'private-App.Models.User.'.$this->ayesha->id
            && $event->broadcastWith()['signal']['call_room_id'] === $room->id
            && $event->broadcastWith()['signal']['sender_id'] === $this->sara->id);

        $this->actingAs($this->ayesha)->getJson("/call-rooms/{$room->id}/signals?client_id=caller-tab-01")
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.payload', $offer);
        $this->actingAs($this->ayesha)->getJson("/call-rooms/{$room->id}/signals?client_id=other-tab-02")->assertJsonCount(0, 'data');
        $this->actingAs($this->bilal)->getJson("/call-rooms/{$room->id}/signals?client_id=callee-tab-01")->assertJsonCount(0, 'data');

        // Only to people who joined.
        $this->actingAs($this->ayesha)->postJson("/call-rooms/{$room->id}/participants", ['user_id' => $this->hina->id])->assertCreated();
        $this->actingAs($this->sara)->postJson("/call-rooms/{$room->id}/signals", [
            'client_id' => 'sara-phone-01', 'to_user_id' => $this->hina->id, 'type' => 'offer', 'payload' => $offer,
        ])->assertConflict();
    }

    public function test_leaving_keeps_the_others_connected_and_the_last_two_end_it(): void
    {
        $call = $this->answeredCall($this->ayesha, $this->bilal);
        $room = $this->addAndAnswer($call, $this->ayesha, $this->sara);

        // Ayesha hangs up (with the normal "end call" button): Bilal and Sara stay.
        $this->actingAs($this->ayesha)->postJson("/calls/{$call->id}/end")->assertOk();
        $this->assertTrue($room->fresh()->isActive());
        $this->assertSame(CallRoomParticipant::STATUS_LEFT, $room->participantFor($this->ayesha)->status);
        $this->assertSame(0, Call::query()->active()->involving($this->ayesha)->count());

        $this->actingAs($this->bilal)->postJson("/call-rooms/{$room->id}/leave")->assertOk()->assertJsonPath('room.status', 'ended');
        $this->assertSame(0, Call::query()->active()->count());
        $this->assertSame(CallRoomParticipant::STATUS_LEFT, $room->participantFor($this->sara)->status);
        // Every call ended with a history message in its chat.
        $this->assertSame(Call::count(), Message::query()->where('message_type', Message::TYPE_CALL)->count());
    }

    public function test_declined_and_unanswered_invites_end_a_room_left_with_one_person(): void
    {
        $call = $this->answeredCall($this->ayesha, $this->bilal);
        $room = $this->roomWithInvite($call, $this->ayesha, $this->sara);

        // Bilal leaves while Sara is still ringing: Ayesha waits for her.
        $this->actingAs($this->bilal)->postJson("/call-rooms/{$room->id}/leave")->assertOk();
        $this->assertTrue($room->fresh()->isActive());

        $invite = Call::query()->where('callee_id', $this->sara->id)->sole();
        $this->actingAs($this->sara)->postJson("/calls/{$invite->id}/decline")->assertOk();

        $this->assertSame(CallRoomParticipant::STATUS_DECLINED, $room->participantFor($this->sara)->status);
        $this->assertFalse($room->fresh()->isActive());
        $this->assertSame(0, Call::query()->active()->count());
    }

    public function test_a_group_call_can_be_started_from_the_calls_tab(): void
    {
        $response = $this->actingAs($this->ayesha)->postJson('/call-rooms', [
            'user_ids' => [$this->bilal->id, $this->sara->id],
            'type' => 'video',
            'client_id' => 'ayesha-tab-01',
        ])->assertCreated()
            ->assertJsonPath('room.type', 'video')
            ->assertJsonPath('room.participants.0.status', 'joined')
            ->assertJsonCount(3, 'room.participants');

        $this->assertSame(2, Call::query()->where('call_room_id', $response->json('room.id'))->where('status', 'ringing')->count());

        $this->actingAs($this->ayesha)->postJson('/call-rooms', [
            'user_ids' => [$this->bilal->id, $this->sara->id, $this->hina->id, User::factory()->create()->id],
            'type' => 'audio',
            'client_id' => 'ayesha-tab-01',
        ])->assertUnprocessable();
    }

    public function test_people_whose_devices_disappear_are_removed(): void
    {
        $call = $this->answeredCall($this->ayesha, $this->bilal);
        $room = $this->addAndAnswer($call, $this->ayesha, $this->sara);

        $this->travel(50)->seconds();
        $this->actingAs($this->sara)->postJson("/call-rooms/{$room->id}/heartbeat")->assertOk();
        $this->actingAs($this->bilal)->postJson("/call-rooms/{$room->id}/heartbeat")->assertOk();
        $this->travel(50)->seconds();
        $this->actingAs($this->sara)->postJson("/call-rooms/{$room->id}/heartbeat")->assertOk();

        // Ayesha's device stopped reporting in; Bilal and Sara keep talking.
        $this->assertSame(CallRoomParticipant::STATUS_LEFT, $room->participantFor($this->ayesha)->status);
        $this->assertTrue($room->fresh()->isActive());
    }

    private function answeredCall(User $caller, User $callee): Call
    {
        $conversation = Conversation::query()->between($caller, $callee)->first() ?? Conversation::factory()->between($caller, $callee)->create();
        $id = $this->actingAs($caller)->postJson("/conversations/{$conversation->id}/calls", ['type' => 'audio', 'client_id' => 'caller-tab-01'])
            ->assertCreated()->json('call.id');
        $this->actingAs($callee)->postJson("/calls/{$id}/accept", ['client_id' => 'callee-tab-01'])->assertOk();

        return Call::findOrFail($id);
    }

    private function roomWithInvite(Call $call, User $by, User $invitee): CallRoom
    {
        $roomId = $this->actingAs($by)->postJson("/calls/{$call->id}/participants", ['user_id' => $invitee->id])->assertCreated()->json('room.id');

        return CallRoom::findOrFail($roomId);
    }

    private function addAndAnswer(Call $call, User $by, User $invitee): CallRoom
    {
        $room = $this->roomWithInvite($call, $by, $invitee);
        $invite = Call::query()->where('callee_id', $invitee->id)->where('call_room_id', $room->id)->sole();
        $this->actingAs($invitee)->postJson("/calls/{$invite->id}/accept", ['client_id' => 'sara-phone-01'])->assertOk();

        return $room;
    }
}

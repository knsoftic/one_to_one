<?php

namespace Tests\Feature\Chat;

use App\Events\CallSignalSent;
use App\Events\CallStarted;
use App\Events\CallUpdated;
use App\Models\BlockedUser;
use App\Models\Call;
use App\Models\CallSignal;
use App\Models\Conversation;
use App\Models\DeviceToken;
use App\Models\Message;
use App\Models\User;
use App\Services\CallService;
use App\Services\DeviceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresFirebase;
use Tests\TestCase;

/**
 * Voice & video calls: ringing, answering, declining, hanging up, WebRTC
 * signaling, call history and pushes to phones.
 */
class CallTest extends TestCase
{
    use ConfiguresFirebase, RefreshDatabase;

    private const CALLER_CLIENT = 'caller-tab-0001';

    private const CALLEE_CLIENT = 'callee-phone-0001';

    private User $caller;

    private User $callee;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->caller, $this->callee] = User::factory()->count(2)->create();
        $this->conversation = Conversation::factory()->between($this->caller, $this->callee)->create();
    }

    /* ------------------------------------------------------------------ */
    /* Starting */
    /* ------------------------------------------------------------------ */

    public function test_starting_a_call_rings_the_callee_and_returns_ice_servers(): void
    {
        Event::fake([CallStarted::class]);

        $response = $this->startCall('video')
            ->assertCreated()
            ->assertJsonPath('call.type', 'video')
            ->assertJsonPath('call.status', 'ringing')
            ->assertJsonPath('call.caller_client', self::CALLER_CLIENT)
            ->assertJsonPath('call.callee.id', $this->callee->id)
            ->assertJsonPath('ice_servers.0.urls.0', 'stun:stun.l.google.com:19302');

        Event::assertDispatched(CallStarted::class, fn (CallStarted $event) => $event->call->id === $response->json('call.id')
            && $event->broadcastOn()[0]->name === 'private-App.Models.User.'.$this->callee->id);
    }

    public function test_only_participants_who_are_not_blocked_can_call(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->postJson("/conversations/{$this->conversation->id}/calls", ['type' => 'audio', 'client_id' => self::CALLER_CLIENT])
            ->assertNotFound();

        $this->startCall('hologram')->assertJsonValidationErrors('type');
        $this->actingAs($this->caller)
            ->postJson("/conversations/{$this->conversation->id}/calls", ['type' => 'audio', 'client_id' => 'bad id!'])
            ->assertJsonValidationErrors('client_id');

        BlockedUser::create(['user_id' => $this->callee->id, 'blocked_user_id' => $this->caller->id]);
        $this->startCall()->assertForbidden();

        $this->assertSame(0, Call::count());
    }

    public function test_calls_can_be_switched_off(): void
    {
        config(['chat.calls.enabled' => false]);

        $this->startCall()->assertForbidden();
    }

    public function test_a_caller_already_in_a_call_cannot_start_another(): void
    {
        $this->startCall()->assertCreated();

        $this->startCall()->assertStatus(409)->assertJsonPath('message', 'You are already in a call.');
    }

    public function test_calling_someone_who_is_on_another_call_ends_as_busy(): void
    {
        $other = User::factory()->create();
        $otherConversation = Conversation::factory()->between($other, $this->callee)->create();
        $this->actingAs($other)
            ->postJson("/conversations/{$otherConversation->id}/calls", ['type' => 'audio', 'client_id' => 'other-client-01'])
            ->assertCreated();

        $this->startCall()
            ->assertCreated()
            ->assertJsonPath('call.status', 'ended')
            ->assertJsonPath('call.end_reason', 'busy');

        // The callee sees a missed call from the caller.
        $history = Message::where('conversation_id', $this->conversation->id)->sole();
        $this->assertSame(Message::TYPE_CALL, $history->message_type);
        $this->assertNull($history->seen_at);
        $this->assertSame('📞 Missed voice call', $history->preview());
        $this->assertStringContainsString('Missed voice call from', $this->callee->notifications()->sole()->data['title']);
    }

    /* ------------------------------------------------------------------ */
    /* Answering, declining, hanging up */
    /* ------------------------------------------------------------------ */

    public function test_callee_device_reports_ringing_then_answers(): void
    {
        $call = $this->ringingCall();
        Event::fake([CallUpdated::class]);

        $this->actingAs($this->callee)->postJson("/calls/{$call->id}/ringing")->assertOk();
        $this->assertNotNull($call->fresh()->ringing_at);

        $this->actingAs($this->callee)
            ->postJson("/calls/{$call->id}/accept", ['client_id' => self::CALLEE_CLIENT])
            ->assertOk()
            ->assertJsonPath('call.status', 'ongoing')
            ->assertJsonPath('call.callee_client', self::CALLEE_CLIENT)
            ->assertJsonStructure(['ice_servers']);

        Event::assertDispatched(CallUpdated::class, fn (CallUpdated $event) => $event->call->isOngoing()
            && count($event->broadcastOn()) === 2);
    }

    public function test_a_call_can_be_answered_on_one_device_only(): void
    {
        $call = $this->ringingCall();

        $this->actingAs($this->caller)
            ->postJson("/calls/{$call->id}/accept", ['client_id' => self::CALLER_CLIENT])
            ->assertForbidden();

        $this->actingAs($this->callee)->postJson("/calls/{$call->id}/accept", ['client_id' => self::CALLEE_CLIENT])->assertOk();

        $this->actingAs($this->callee)
            ->postJson("/calls/{$call->id}/accept", ['client_id' => 'callee-laptop-01'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'The call was answered on another device.');
    }

    public function test_declined_call_is_logged_without_notifying_the_callee(): void
    {
        $call = $this->ringingCall();

        $this->actingAs($this->callee)
            ->postJson("/calls/{$call->id}/decline")
            ->assertOk()
            ->assertJsonPath('call.end_reason', 'declined')
            ->assertJsonPath('ice_servers', []);

        $history = Message::sole();
        $this->assertNotNull($history->seen_at);
        $this->assertSame($call->id, $history->attachment_meta['call_id']);
        $this->assertSame($history->id, $call->fresh()->message_id);
        $this->assertSame($history->id, $this->conversation->fresh()->last_message_id);
        $this->assertSame(0, $this->callee->notifications()->count());
    }

    public function test_caller_hanging_up_before_answer_is_a_missed_call_for_the_callee(): void
    {
        $call = $this->ringingCall();

        $this->actingAs($this->caller)->postJson("/calls/{$call->id}/end")->assertOk()->assertJsonPath('call.end_reason', 'cancelled');

        $this->assertNull(Message::sole()->seen_at);
        $this->assertSame(1, $this->callee->unreadNotifications()->count());

        $this->actingAs($this->callee)
            ->getJson("/conversations/{$this->conversation->id}/messages")
            ->assertJsonPath('data.0.type', 'call')
            ->assertJsonPath('data.0.call.reason', 'cancelled')
            ->assertJsonPath('data.0.call.type', 'audio')
            ->assertJsonPath('data.0.is_mine', false);
    }

    public function test_unanswered_call_timing_out_on_the_caller_is_missed(): void
    {
        $call = $this->ringingCall();

        $this->actingAs($this->caller)->postJson("/calls/{$call->id}/end", ['reason' => 'no_answer'])->assertJsonPath('call.end_reason', 'missed');
    }

    public function test_hanging_up_an_answered_call_records_its_duration_once(): void
    {
        $call = $this->answeredCall();
        $this->travel(95)->seconds();

        $this->actingAs($this->callee)
            ->postJson("/calls/{$call->id}/end")
            ->assertOk()
            ->assertJsonPath('call.status', 'ended')
            ->assertJsonPath('call.end_reason', 'completed')
            ->assertJsonPath('call.duration', 95);

        // The other side hanging up at the same moment changes nothing.
        $this->actingAs($this->caller)->postJson("/calls/{$call->id}/end")->assertOk()->assertJsonPath('call.duration', 95);

        $this->assertSame(1, Message::count());
        $this->assertSame(95, Message::sole()->attachment_meta['duration']);
        $this->assertSame('📞 Voice call', Message::sole()->preview());
        $this->assertSame(0, $this->callee->notifications()->count());
    }

    public function test_strangers_cannot_see_or_touch_a_call(): void
    {
        $call = $this->ringingCall();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->getJson("/calls/{$call->id}")->assertNotFound();
        $this->actingAs($stranger)->postJson("/calls/{$call->id}/accept", ['client_id' => 'stranger-001'])->assertNotFound();
        $this->actingAs($stranger)->postJson("/calls/{$call->id}/end")->assertNotFound();
        $this->actingAs($stranger)->getJson("/calls/{$call->id}/signals?client_id=stranger-001")->assertNotFound();

        $this->assertTrue($call->fresh()->isRinging());
    }

    public function test_call_history_can_only_be_deleted_for_yourself(): void
    {
        $call = $this->answeredCall();
        $this->actingAs($this->caller)->postJson("/calls/{$call->id}/end");
        $history = Message::sole();

        $this->actingAs($this->caller)
            ->deleteJson("/messages/{$history->id}", ['scope' => 'everyone'])
            ->assertForbidden();

        $this->actingAs($this->caller)->deleteJson("/messages/{$history->id}", ['scope' => 'me'])->assertOk();
    }

    /* ------------------------------------------------------------------ */
    /* Signaling */
    /* ------------------------------------------------------------------ */

    public function test_signals_reach_only_the_device_they_are_addressed_to(): void
    {
        $call = $this->answeredCall();
        Event::fake([CallSignalSent::class]);

        $offer = json_encode(['type' => 'offer', 'sdp' => str_repeat('a=candidate:x ', 700)]);
        $this->actingAs($this->caller)
            ->postJson("/calls/{$call->id}/signals", [
                'client_id' => self::CALLER_CLIENT,
                'to_client' => self::CALLEE_CLIENT,
                'type' => 'offer',
                'payload' => $offer,
            ])
            ->assertCreated();

        // Large session descriptions are fetched over HTTP, small candidates travel in the event.
        Event::assertDispatched(CallSignalSent::class, fn (CallSignalSent $event) => $event->broadcastWith()['signal']['payload'] === null
            && $event->broadcastOn()[0]->name === 'private-App.Models.User.'.$this->callee->id);

        $candidate = json_encode(['candidate' => 'candidate:1 1 udp 2122260223 192.0.2.1 54400 typ host', 'sdpMid' => '0']);
        $this->actingAs($this->caller)->postJson("/calls/{$call->id}/signals", [
            'client_id' => self::CALLER_CLIENT, 'to_client' => self::CALLEE_CLIENT, 'type' => 'candidate', 'payload' => $candidate,
        ])->assertCreated();
        Event::assertDispatched(CallSignalSent::class, fn (CallSignalSent $event) => $event->broadcastWith()['signal']['payload'] === $candidate);

        $this->actingAs($this->callee)
            ->getJson("/calls/{$call->id}/signals?client_id=".self::CALLEE_CLIENT)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', 'offer')
            ->assertJsonPath('data.0.payload', $offer)
            ->assertJsonPath('data.0.from_client', self::CALLER_CLIENT);

        $firstId = CallSignal::orderBy('id')->value('id');
        $this->actingAs($this->callee)
            ->getJson("/calls/{$call->id}/signals?client_id=".self::CALLEE_CLIENT."&after={$firstId}")
            ->assertJsonCount(1, 'data');

        // Another device of the callee and the sender itself receive nothing.
        $this->actingAs($this->callee)->getJson("/calls/{$call->id}/signals?client_id=callee-laptop-01")->assertJsonCount(0, 'data');
        $this->actingAs($this->caller)->getJson("/calls/{$call->id}/signals?client_id=".self::CALLER_CLIENT)->assertJsonCount(0, 'data');
    }

    public function test_signals_are_validated_and_removed_when_the_call_ends(): void
    {
        $call = $this->answeredCall();
        $signal = fn (array $overrides = []) => $this->actingAs($this->caller)->postJson("/calls/{$call->id}/signals", $overrides + [
            'client_id' => self::CALLER_CLIENT, 'type' => 'candidate', 'payload' => '{"candidate":""}',
        ]);

        $signal(['type' => 'script'])->assertJsonValidationErrors('type');
        $signal(['payload' => 'not json'])->assertJsonValidationErrors('payload');
        $signal()->assertCreated();

        $this->actingAs($this->caller)->postJson("/calls/{$call->id}/end");

        $this->assertSame(0, CallSignal::count());
        $signal()->assertStatus(409);
    }

    /* ------------------------------------------------------------------ */
    /* Recovery & cleanup */
    /* ------------------------------------------------------------------ */

    public function test_active_calls_are_listed_and_included_in_polling_sync(): void
    {
        $call = $this->ringingCall();

        $this->actingAs($this->callee)->getJson('/calls/active')->assertOk()->assertJsonPath('data.0.id', $call->id);

        $this->actingAs($this->callee)
            ->getJson('/chat/sync?since='.urlencode(now()->subMinute()->toIso8601String()))
            ->assertOk()
            ->assertJsonPath('calls.0.id', $call->id)
            ->assertJsonPath('calls.0.caller.id', $this->caller->id);
    }

    public function test_stale_calls_are_closed(): void
    {
        $unanswered = $this->ringingCall();
        $this->travel(59)->seconds();
        $this->assertSame(0, app(CallService::class)->expireStale());
        $this->travel(2)->seconds();

        // Starting a new call clears the one nobody answered, so the callee is not "busy".
        $other = User::factory()->create();
        $otherConversation = Conversation::factory()->between($other, $this->callee)->create();
        $abandoned = app(CallService::class)->start($other, $otherConversation, 'video', 'other-client-01');

        $this->assertSame('missed', $unanswered->fresh()->end_reason);
        $this->assertTrue($abandoned->fresh()->isRinging());

        app(CallService::class)->accept($abandoned, $this->callee, self::CALLEE_CLIENT);
        $this->travel(40)->seconds();
        app(CallService::class)->touch($abandoned->fresh(), $other);
        $this->travel(100)->seconds();

        $this->artisan('chat:expire-calls')->assertSuccessful();

        $abandoned->refresh();
        $this->assertSame('completed', $abandoned->end_reason);
        $this->assertSame(40, $abandoned->duration); // until the devices were last heard from
    }

    public function test_turn_credentials_are_short_lived_and_signed(): void
    {
        config([
            'chat.calls.turn_urls' => 'turn:turn.example.com:3478?transport=udp, turns:turn.example.com:5349, http://evil.test',
            'chat.calls.turn_secret' => 'coturn-shared-secret',
        ]);

        $servers = $this->startCall()->assertCreated()->json('ice_servers');
        $turn = $servers[1];

        $this->assertSame(['turn:turn.example.com:3478?transport=udp', 'turns:turn.example.com:5349'], $turn['urls']);
        [$expiry, $userId] = explode(':', $turn['username']);
        $this->assertSame((string) $this->caller->id, $userId);
        $this->assertGreaterThan(time(), (int) $expiry);
        $this->assertSame(base64_encode(hash_hmac('sha1', $turn['username'], 'coturn-shared-secret', true)), $turn['credential']);
    }

    /* ------------------------------------------------------------------ */
    /* Phones */
    /* ------------------------------------------------------------------ */

    public function test_incoming_call_and_its_end_are_pushed_to_the_callees_phone(): void
    {
        $this->configureFirebase();
        $this->fakeFirebase();
        $token = 'fcm-token-for-calls-1234567890:APA91bExample';
        app(DeviceService::class)->issue($this->callee, 'android')['device']
            ->forceFill(['fcm_token' => $token, 'fcm_token_hash' => DeviceToken::hashToken($token)])->save();

        $callId = $this->startCall('video')->assertCreated()->json('call.id');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'messages:send')
            && $request['message']['data']['type'] === 'call'
            && $request['message']['data']['call_id'] === (string) $callId
            && $request['message']['data']['call_type'] === 'video'
            && $request['message']['data']['caller_name'] === $this->caller->name
            && $request['message']['android'] === ['priority' => 'HIGH', 'ttl' => '45s']);

        $this->actingAs($this->caller)->postJson("/calls/{$callId}/end")->assertOk();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'messages:send')
            && $request['message']['data']['type'] === 'call_state'
            && $request['message']['data']['status'] === 'ended'
            && $request['message']['data']['end_reason'] === 'cancelled');
    }

    public function test_phone_can_ring_and_decline_while_the_app_is_closed(): void
    {
        $call = $this->ringingCall();
        $token = app(DeviceService::class)->issue($this->callee, 'android')['token'];

        $this->withToken($token)->postJson("/api/device/calls/{$call->id}/ringing")->assertOk()->assertJsonPath('status', 'ringing');
        $this->assertNotNull($call->fresh()->ringing_at);

        $this->withToken($token)->postJson("/api/device/calls/{$call->id}/decline")
            ->assertOk()
            ->assertJsonPath('status', 'ended')
            ->assertJsonPath('end_reason', 'declined');

        $strangerToken = app(DeviceService::class)->issue(User::factory()->create(), 'android')['token'];
        $this->withToken($strangerToken)->postJson("/api/device/calls/{$call->id}/end")->assertNotFound();
    }

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    private function startCall(string $type = 'audio')
    {
        return $this->actingAs($this->caller)->postJson("/conversations/{$this->conversation->id}/calls", [
            'type' => $type,
            'client_id' => self::CALLER_CLIENT,
        ]);
    }

    private function ringingCall(): Call
    {
        return Call::findOrFail($this->startCall()->assertCreated()->json('call.id'));
    }

    private function answeredCall(): Call
    {
        $call = $this->ringingCall();
        $this->actingAs($this->callee)->postJson("/calls/{$call->id}/accept", ['client_id' => self::CALLEE_CLIENT])->assertOk();

        return $call->fresh();
    }
}

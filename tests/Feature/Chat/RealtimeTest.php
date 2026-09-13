<?php

namespace Tests\Feature\Chat;

use App\Events\MessageSent;
use App\Events\MessagesStatusUpdated;
use App\Events\UserPresenceChanged;
use App\Events\UserTyping;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RealtimeTest extends TestCase
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

    public function test_sending_a_message_broadcasts_to_both_participants(): void
    {
        Event::fake([MessageSent::class]);

        $this->actingAs($this->me)
            ->postJson("/conversations/{$this->conversation->id}/messages", ['message' => 'Hello'])
            ->assertCreated();

        Event::assertDispatched(MessageSent::class, function (MessageSent $event) {
            $channels = collect($event->broadcastOn())->map->name->all();

            return $event->message->message === 'Hello'
                && in_array('private-App.Models.User.'.$this->friend->id, $channels, true)
                && in_array('private-App.Models.User.'.$this->me->id, $channels, true)
                && $event->broadcastAs() === 'message.sent'
                && $event->broadcastWith()['message']['body'] === 'Hello';
        });
    }

    public function test_receiver_can_acknowledge_delivery_of_their_messages_only(): void
    {
        Event::fake([MessagesStatusUpdated::class]);

        $toMe = Message::factory()->inConversation($this->conversation, $this->friend)->count(2)->create();
        $fromMe = Message::factory()->inConversation($this->conversation, $this->me)->create();

        $this->actingAs($this->me)
            ->postJson('/messages/delivered', ['ids' => [...$toMe->pluck('id'), $fromMe->id]])
            ->assertOk()
            ->assertJsonPath('updated', 2);

        $this->assertNotNull($toMe[0]->fresh()->delivered_at);
        $this->assertNull($fromMe->fresh()->delivered_at, 'Senders must not be able to mark their own messages delivered.');

        Event::assertDispatched(MessagesStatusUpdated::class, fn (MessagesStatusUpdated $e) => $e->status === 'delivered'
            && $e->senderId === $this->friend->id
            && $e->messageIds === $toMe->pluck('id')->all());
    }

    public function test_opening_a_conversation_marks_messages_seen(): void
    {
        Event::fake([MessagesStatusUpdated::class]);

        $unread = Message::factory()->inConversation($this->conversation, $this->friend)->count(3)->create();

        $this->actingAs($this->me)
            ->postJson("/conversations/{$this->conversation->id}/seen")
            ->assertOk()
            ->assertJsonCount(3, 'ids');

        $unread->each(function (Message $message) {
            $fresh = $message->fresh();
            $this->assertNotNull($fresh->seen_at);
            $this->assertNotNull($fresh->delivered_at);
            $this->assertSame('seen', $fresh->status());
        });

        $this->actingAs($this->me)->getJson('/conversations')->assertJsonPath('0.unread_count', 0);

        Event::assertDispatched(MessagesStatusUpdated::class, fn (MessagesStatusUpdated $e) => $e->status === 'seen'
            && $e->senderId === $this->friend->id
            && count($e->messageIds) === 3);

        // Nothing left to mark: no second broadcast.
        Event::fake([MessagesStatusUpdated::class]);
        $this->actingAs($this->me)->postJson("/conversations/{$this->conversation->id}/seen")->assertJsonCount(0, 'ids');
        Event::assertNotDispatched(MessagesStatusUpdated::class);
    }

    public function test_sender_cannot_mark_their_own_messages_seen(): void
    {
        $mine = Message::factory()->inConversation($this->conversation, $this->me)->create();

        $this->actingAs($this->me)->postJson("/conversations/{$this->conversation->id}/seen")->assertJsonCount(0, 'ids');

        $this->assertNull($mine->fresh()->seen_at);
    }

    public function test_outsiders_cannot_touch_receipts_or_typing(): void
    {
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->postJson("/conversations/{$this->conversation->id}/seen")->assertNotFound();
        $this->actingAs($intruder)->postJson("/conversations/{$this->conversation->id}/typing", ['typing' => true])->assertNotFound();
    }

    public function test_typing_indicator_is_broadcast_and_cached(): void
    {
        Event::fake([UserTyping::class]);

        $this->actingAs($this->me)
            ->postJson("/conversations/{$this->conversation->id}/typing", ['typing' => true])
            ->assertOk();

        Event::assertDispatched(UserTyping::class, fn (UserTyping $e) => $e->typing
            && $e->recipientId === $this->friend->id
            && $e->userId === $this->me->id);

        $this->assertTrue(Cache::has("chat:typing:{$this->conversation->id}:{$this->me->id}"));

        $this->actingAs($this->me)->postJson("/conversations/{$this->conversation->id}/typing", ['typing' => false])->assertOk();
        $this->assertFalse(Cache::has("chat:typing:{$this->conversation->id}:{$this->me->id}"));
    }

    public function test_heartbeat_marks_user_online_and_delivers_pending_messages(): void
    {
        Event::fake([UserPresenceChanged::class, MessagesStatusUpdated::class]);
        $pending = Message::factory()->inConversation($this->conversation, $this->friend)->create();

        $this->actingAs($this->me)->postJson('/presence/heartbeat')->assertOk()->assertJsonPath('delivered', 1);

        $this->assertTrue($this->me->fresh()->isOnlineNow());
        $this->assertNotNull($pending->fresh()->delivered_at);
        Event::assertDispatched(UserPresenceChanged::class, fn (UserPresenceChanged $e) => $e->userId === $this->me->id && $e->isOnline);
    }

    public function test_offline_beacon_marks_user_offline(): void
    {
        Event::fake([UserPresenceChanged::class]);
        $user = User::factory()->online()->create();

        $this->actingAs($user)->post('/presence/offline')->assertNoContent();

        $this->assertFalse($user->fresh()->is_online);
        Event::assertDispatched(UserPresenceChanged::class, fn (UserPresenceChanged $e) => ! $e->isOnline);
    }

    public function test_stale_users_are_swept_offline(): void
    {
        $stale = User::factory()->create(['is_online' => true, 'last_seen' => now()->subMinutes(10)]);
        $fresh = User::factory()->online()->create();

        $this->artisan('chat:sweep-presence')->assertSuccessful();

        $this->assertFalse($stale->fresh()->is_online);
        $this->assertTrue($fresh->fresh()->is_online);
    }

    public function test_sync_returns_changes_typing_and_presence_for_the_user_only(): void
    {
        $since = now()->subMinute();

        $incoming = Message::factory()->inConversation($this->conversation, $this->friend)->create(['message' => 'new one']);
        $hidden = Message::factory()->inConversation($this->conversation, $this->me)->create();
        $hidden->forceFill(['deleted_for_sender' => true])->save();

        $strangers = Conversation::factory()->create();
        Message::factory()->inConversation($strangers, $strangers->userOne)->create(['message' => 'not yours']);

        Cache::put("chat:typing:{$this->conversation->id}:{$this->friend->id}", true, 5);
        $this->friend->forceFill(['is_online' => true, 'last_seen' => now()])->save();

        $response = $this->actingAs($this->me)
            ->getJson('/chat/sync?'.http_build_query(['since' => $since->toIso8601String(), 'conversation_id' => $this->conversation->id]))
            ->assertOk()
            ->assertJsonCount(2, 'messages')
            ->assertJsonPath('typing.typing', true)
            ->assertJsonPath('presence.0.id', $this->friend->id)
            ->assertJsonPath('presence.0.is_online', true)
            ->assertDontSee('not yours');

        $messages = collect($response->json('messages'))->keyBy('id');
        $this->assertSame('new one', $messages[$incoming->id]['body']);
        $this->assertTrue($messages[$hidden->id]['hidden']);
        $this->assertArrayNotHasKey('body', $messages[$hidden->id]);
    }

    public function test_sync_requires_a_since_timestamp(): void
    {
        $this->actingAs($this->me)->getJson('/chat/sync')->assertUnprocessable();
    }

    public function test_messages_are_saved_even_when_the_websocket_server_is_down(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 9, // nothing listens here
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);

        $this->actingAs($this->me)
            ->postJson("/conversations/{$this->conversation->id}/messages", ['message' => 'still saved'])
            ->assertCreated();

        $this->assertDatabaseHas('messages', ['message' => 'still saved']);
    }
}

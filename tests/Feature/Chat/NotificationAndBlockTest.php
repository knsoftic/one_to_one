<?php

namespace Tests\Feature\Chat;

use App\Events\BlockStatusChanged;
use App\Models\BlockedUser;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\NewMessageNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationAndBlockTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $friend;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create(['name' => 'Awais Ahmed']);
        $this->friend = User::factory()->create(['name' => 'Ahmed Khan']);
        $this->conversation = Conversation::factory()->between($this->me, $this->friend)->create();
    }

    private function sendAs(User $user, string $text = 'Hello brother')
    {
        return $this->actingAs($user)->postJson("/conversations/{$this->conversation->id}/messages", ['message' => $text]);
    }

    /* ------------------------------------------------------------------ */
    /* Notifications */
    /* ------------------------------------------------------------------ */

    public function test_receiver_is_notified_of_a_new_message(): void
    {
        Notification::fake();

        $this->sendAs($this->friend)->assertCreated();

        Notification::assertSentTo($this->me, NewMessageNotification::class, function (NewMessageNotification $notification, array $channels) {
            $data = $notification->toArray($this->me);

            return $channels === ['database', 'broadcast']
                && $data['title'] === 'Ahmed Khan sent you a message'
                && $data['body'] === 'Hello brother'
                && $data['conversation_id'] === $this->conversation->id;
        });

        Notification::assertNotSentTo($this->friend, NewMessageNotification::class);
    }

    public function test_disabled_notifications_are_only_stored(): void
    {
        Notification::fake();
        $this->me->forceFill(['notifications_enabled' => false])->save();

        $this->sendAs($this->friend)->assertCreated();

        Notification::assertSentTo($this->me, NewMessageNotification::class, fn ($n, array $channels) => $channels === ['database']);
    }

    public function test_notification_centre_lists_and_marks_notifications_read(): void
    {
        $this->sendAs($this->friend, 'First')->assertCreated();
        $this->sendAs($this->friend, 'Second')->assertCreated();

        $this->actingAs($this->me)->getJson('/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 2)
            ->assertJsonPath('data.0.data.title', 'Ahmed Khan sent you a message')
            ->assertJsonPath('data.0.data.sender.name', 'Ahmed Khan')
            ->assertJsonMissingPath('data.0.data.sender.email');

        // Opening the conversation marks its notifications as read.
        $this->actingAs($this->me)->postJson("/conversations/{$this->conversation->id}/seen")->assertOk();
        $this->actingAs($this->me)->getJson('/notifications')->assertJsonPath('unread_count', 0);

        $this->sendAs($this->friend, 'Third')->assertCreated();
        $this->actingAs($this->me)->postJson('/notifications/read')->assertOk()->assertJsonPath('unread_count', 0);
    }

    public function test_users_only_see_their_own_notifications(): void
    {
        $this->sendAs($this->friend)->assertCreated();

        $this->actingAs($this->friend)->getJson('/notifications')->assertJsonPath('unread_count', 0)->assertJsonCount(0, 'data');
        $this->actingAs(User::factory()->create())->getJson('/notifications')->assertJsonCount(0, 'data');
    }

    /* ------------------------------------------------------------------ */
    /* Blocking */
    /* ------------------------------------------------------------------ */

    public function test_users_can_block_and_unblock(): void
    {
        Event::fake([BlockStatusChanged::class]);

        $this->actingAs($this->me)->postJson("/users/{$this->friend->id}/block")->assertCreated();
        $this->assertTrue($this->me->hasBlocked($this->friend));

        // Idempotent.
        $this->actingAs($this->me)->postJson("/users/{$this->friend->id}/block")->assertCreated();
        $this->assertSame(1, BlockedUser::count());

        Event::assertDispatched(BlockStatusChanged::class, fn (BlockStatusChanged $e) => $e->blocked
            && $e->blockerId === $this->me->id
            && $e->blockedId === $this->friend->id
            && $e->conversationId === $this->conversation->id);

        $this->actingAs($this->me)->deleteJson("/users/{$this->friend->id}/block")->assertOk();
        $this->assertFalse($this->me->fresh()->hasBlocked($this->friend));
    }

    public function test_users_cannot_block_themselves(): void
    {
        $this->actingAs($this->me)->postJson("/users/{$this->me->id}/block")->assertStatus(422);
        $this->assertSame(0, BlockedUser::count());
    }

    public function test_blocked_user_cannot_send_new_messages_but_history_remains(): void
    {
        Message::factory()->inConversation($this->conversation, $this->friend)->create(['message' => 'before block']);
        BlockedUser::create(['user_id' => $this->me->id, 'blocked_user_id' => $this->friend->id]);

        $this->sendAs($this->friend, 'after block')
            ->assertForbidden()
            ->assertJsonPath('message', 'You can no longer send messages to this user.');

        $this->sendAs($this->me, 'blocker message')
            ->assertForbidden()
            ->assertJsonPath('message', 'You blocked this user. Unblock them to send messages.');

        $this->assertDatabaseMissing('messages', ['message' => 'after block']);

        $this->actingAs($this->friend)->getJson("/conversations/{$this->conversation->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.body', 'before block');
        $this->actingAs($this->me)->getJson("/conversations/{$this->conversation->id}/messages")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_blocked_users_cannot_type_edit_or_upload(): void
    {
        $own = Message::factory()->inConversation($this->conversation, $this->friend)->create();
        BlockedUser::create(['user_id' => $this->me->id, 'blocked_user_id' => $this->friend->id]);

        $this->actingAs($this->friend)->postJson("/conversations/{$this->conversation->id}/typing", ['typing' => true])->assertForbidden();
        $this->actingAs($this->friend)->patchJson("/messages/{$own->id}", ['message' => 'edited'])->assertForbidden();
        $this->actingAs($this->friend)->post(
            "/conversations/{$this->conversation->id}/messages",
            ['attachment' => UploadedFile::fake()->image('a.jpg')],
            ['Accept' => 'application/json'],
        )->assertForbidden();

        // Deleting your own messages is still allowed.
        $this->actingAs($this->friend)->deleteJson("/messages/{$own->id}", ['scope' => 'me'])->assertOk();
    }

    public function test_conversation_exposes_block_flags_and_hides_blocker_presence(): void
    {
        Message::factory()->inConversation($this->conversation, $this->friend)->create();
        $this->me->forceFill(['is_online' => true, 'last_seen' => now()])->save();
        BlockedUser::create(['user_id' => $this->me->id, 'blocked_user_id' => $this->friend->id]);

        $this->actingAs($this->me)->getJson('/conversations')
            ->assertJsonPath('0.blocked_by_me', true)
            ->assertJsonPath('0.blocked_me', false);

        $this->actingAs($this->friend)->getJson("/conversations/{$this->conversation->id}")
            ->assertJsonPath('blocked_by_me', false)
            ->assertJsonPath('blocked_me', true)
            ->assertJsonPath('participant.is_online', false)
            ->assertJsonPath('participant.last_seen', null);
    }

    public function test_unblocking_from_settings_redirects_back(): void
    {
        BlockedUser::create(['user_id' => $this->me->id, 'blocked_user_id' => $this->friend->id]);

        $this->actingAs($this->me)->get('/settings?tab=blocked')->assertOk()->assertSee('Ahmed Khan');

        $this->actingAs($this->me)
            ->from('/settings?tab=blocked')
            ->delete("/users/{$this->friend->id}/block")
            ->assertRedirect('/settings?tab=blocked')
            ->assertSessionHas('status', 'Ahmed Khan has been unblocked.');

        $this->assertSame(0, BlockedUser::count());
    }
}

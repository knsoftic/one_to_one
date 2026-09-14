<?php

namespace Tests\Feature\Chat;

use App\Events\ChatSettingsUpdated;
use App\Models\ChatSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\StarredMessage;
use App\Models\User;
use App\Notifications\NewMessageNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Phase 2 — C1 pin, C2 mute, C3 archive, C4 mark unread/read, C5 clear/delete.
 */
class ChatListSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $friend;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->me, $this->friend] = User::factory()->count(2)->create();
        $this->conversation = $this->chatWith($this->friend);
    }

    public function test_c1_up_to_three_chats_can_be_pinned_and_only_i_see_it(): void
    {
        Event::fake([ChatSettingsUpdated::class]);

        $chats = collect(range(1, 3))->map(fn () => $this->chatWith(User::factory()->create()));
        foreach ($chats as $chat) {
            $this->settings($chat, ['pinned' => true])->assertOk()->assertJsonPath('settings.pinned', true);
        }

        $this->settings($this->conversation, ['pinned' => true])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'You can pin up to 3 chats.');

        $this->settings($chats[0], ['pinned' => false])->assertJsonPath('settings.pinned', false);
        $this->settings($this->conversation, ['pinned' => true])->assertOk();

        // The other person's view is unchanged.
        $this->actingAs($this->friend)->getJson("/conversations/{$this->conversation->id}")->assertJsonPath('settings.pinned', false);
        $this->actingAs(User::factory()->create())->patchJson("/conversations/{$this->conversation->id}/settings", ['pinned' => true])->assertNotFound();

        Event::assertDispatched(ChatSettingsUpdated::class, fn ($event) => $event->broadcastWith() === ['conversation_id' => $this->conversation->id]);
    }

    public function test_c2_muted_chats_count_as_unread_but_do_not_notify(): void
    {
        Notification::fake();

        $this->freezeTime();
        $this->settings($this->conversation, ['muted' => '8h'])
            ->assertOk()
            ->assertJsonPath('settings.muted', true)
            ->assertJsonPath('settings.mute_always', false)
            ->assertJsonPath('settings.muted_until', now()->addHours(8)->toIso8601String());

        $this->messageFrom($this->friend, 'Are you there?');
        Notification::assertNotSentTo($this->me, NewMessageNotification::class);
        $this->actingAs($this->me)->getJson('/conversations')->assertJsonPath('0.unread_count', 2); // "hello" + this one

        $this->travel(9)->hours();
        $this->actingAs($this->me)->getJson("/conversations/{$this->conversation->id}")->assertJsonPath('settings.muted', false);
        $this->messageFrom($this->friend, 'Hello again');
        Notification::assertSentTo($this->me, NewMessageNotification::class);

        $this->settings($this->conversation, ['muted' => 'always'])->assertJsonPath('settings.mute_always', true);
        $this->settings($this->conversation, ['muted' => null])->assertJsonPath('settings.muted', false);
        $this->settings($this->conversation, ['muted' => '2d'])->assertJsonValidationErrors('muted');
    }

    public function test_c3_archived_chats_stay_archived_when_messages_arrive_and_unpin(): void
    {
        $this->settings($this->conversation, ['pinned' => true]);
        $this->settings($this->conversation, ['archived' => true])
            ->assertJsonPath('settings.archived', true)
            ->assertJsonPath('settings.pinned', false);

        $this->messageFrom($this->friend, 'New message');

        $this->actingAs($this->me)->getJson('/conversations')->assertJsonPath('0.settings.archived', true);

        $this->settings($this->conversation, ['archived' => false])->assertJsonPath('settings.archived', false);
    }

    public function test_c4_mark_as_unread_until_opened_and_mark_as_read_reads_everything(): void
    {
        $this->messageFrom($this->friend, 'One');
        $this->messageFrom($this->friend, 'Two');

        $this->settings($this->conversation, ['unread' => false])
            ->assertOk()
            ->assertJsonPath('unread_count', 0)
            ->assertJsonPath('settings.marked_unread', false);
        $this->assertSame(0, Message::whereNull('seen_at')->count());

        $this->settings($this->conversation, ['unread' => true])->assertJsonPath('settings.marked_unread', true);

        // Opening the chat (seen) removes the mark.
        $this->actingAs($this->me)->postJson("/conversations/{$this->conversation->id}/seen")->assertOk();
        $this->actingAs($this->me)->getJson("/conversations/{$this->conversation->id}")->assertJsonPath('settings.marked_unread', false);
    }

    public function test_c5_clear_chat_hides_messages_for_me_only_and_keeps_the_chat_listed(): void
    {
        $first = $this->messageFrom($this->friend, 'Old news');
        $mine = $this->messageFrom($this->me, 'My reply');
        StarredMessage::query()->create(['user_id' => $this->me->id, 'message_id' => $first->id]);

        $this->actingAs($this->me)->postJson("/conversations/{$this->conversation->id}/clear")
            ->assertOk()
            ->assertJsonPath('last_message', null)
            ->assertJsonPath('unread_count', 0);

        $this->actingAs($this->me)->getJson("/conversations/{$this->conversation->id}/messages")->assertJsonCount(0, 'data');
        $this->actingAs($this->me)->getJson("/conversations/{$this->conversation->id}/messages/search?q=news")->assertJsonCount(0, 'data');
        $this->assertSame(0, StarredMessage::count());

        // Still in my list (empty), and untouched for the other person.
        $this->actingAs($this->me)->getJson('/conversations')->assertJsonPath('0.id', $this->conversation->id);
        $this->actingAs($this->friend)->getJson("/conversations/{$this->conversation->id}/messages")->assertJsonCount(3, 'data');

        $this->messageFrom($this->friend, 'After clearing');
        $this->actingAs($this->me)->getJson("/conversations/{$this->conversation->id}/messages")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'After clearing');

        $this->assertNotNull($mine->fresh());
    }

    public function test_c5_clear_can_keep_starred_messages(): void
    {
        $message = $this->messageFrom($this->friend, 'Address: 12 Mall Road');
        StarredMessage::query()->create(['user_id' => $this->me->id, 'message_id' => $message->id]);

        $this->actingAs($this->me)->postJson("/conversations/{$this->conversation->id}/clear", ['keep_starred' => true])->assertOk();

        $this->assertSame(1, StarredMessage::count());
    }

    public function test_c5_delete_chat_removes_it_from_my_list_until_a_new_message(): void
    {
        $this->messageFrom($this->friend, 'Hi');
        $this->settings($this->conversation, ['pinned' => true, 'favorite' => true]);

        $this->actingAs($this->me)->deleteJson("/conversations/{$this->conversation->id}")->assertOk();

        $this->actingAs($this->me)->getJson('/conversations')->assertJsonCount(0);
        $this->actingAs($this->me)->getJson("/conversations/{$this->conversation->id}")->assertJsonPath('settings.hidden', true);
        $this->actingAs($this->friend)->getJson('/conversations')->assertJsonCount(1);

        $setting = ChatSetting::sole();
        $this->assertNull($setting->pinned_at);
        $this->assertNull($setting->favorite_at);

        $this->messageFrom($this->friend, 'Are you back?');
        $this->actingAs($this->me)->getJson('/conversations')
            ->assertJsonCount(1)
            ->assertJsonPath('0.last_message.preview', 'Are you back?')
            ->assertJsonPath('0.unread_count', 1);
    }

    private function chatWith(User $other): Conversation
    {
        $conversation = Conversation::factory()->between($this->me, $other)->create();
        Message::factory()->inConversation($conversation, $other)->create(['message' => 'hello']);

        return $conversation;
    }

    private function settings(Conversation $conversation, array $changes)
    {
        return $this->actingAs($this->me)->patchJson("/conversations/{$conversation->id}/settings", $changes);
    }

    private function messageFrom(User $sender, string $text): Message
    {
        $id = $this->actingAs($sender)->postJson("/conversations/{$this->conversation->id}/messages", ['message' => $text])->assertCreated()->json('id');

        return Message::findOrFail($id);
    }
}

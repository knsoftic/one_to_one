<?php

namespace Tests\Feature\Chat;

use App\Events\ChatListsUpdated;
use App\Models\ChatList;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * C6 — Favourites and a person's own chat lists.
 */
class ChatListsTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create();
    }

    public function test_favourites_are_a_private_setting(): void
    {
        $chat = $this->chat();

        $this->actingAs($this->me)->patchJson("/conversations/{$chat->id}/settings", ['favorite' => true])
            ->assertOk()
            ->assertJsonPath('settings.favorite', true);

        $this->actingAs($this->me)->getJson('/conversations')->assertJsonPath('0.settings.favorite', true);
        $this->actingAs($chat->otherParticipant($this->me))->getJson("/conversations/{$chat->id}")->assertJsonPath('settings.favorite', false);
    }

    public function test_lists_are_created_renamed_filled_and_deleted_with_only_my_chats(): void
    {
        Event::fake([ChatListsUpdated::class]);
        [$family, $work] = [$this->chat(), $this->chat()];
        $strangers = Conversation::factory()->between(User::factory()->create(), User::factory()->create())->create();

        $list = $this->actingAs($this->me)->postJson('/chat-lists', ['name' => '  Family   & friends ', 'conversation_ids' => [$family->id, $strangers->id]])
            ->assertCreated()
            ->assertJsonPath('name', 'Family & friends')
            ->assertJsonPath('conversation_ids', [$family->id])
            ->json();

        $this->actingAs($this->me)->patchJson("/chat-lists/{$list['id']}", ['name' => 'Family', 'conversation_ids' => [$family->id, $work->id]])
            ->assertOk()
            ->assertJsonPath('name', 'Family')
            ->assertJsonPath('conversation_ids', [$family->id, $work->id]);

        $this->actingAs($this->me)->postJson('/chat-lists', ['name' => 'Work'])->assertCreated();
        $this->actingAs($this->me)->postJson('/chat-lists', ['name' => 'Family'])->assertJsonValidationErrors(['name' => 'You already have a list with this name.']);

        $this->actingAs($this->me)->getJson('/chat-lists')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Family')
            ->assertJsonPath('data.1.conversation_ids', []);

        // Other people cannot see or change my lists.
        $other = User::factory()->create();
        $this->actingAs($other)->getJson('/chat-lists')->assertJsonCount(0, 'data');
        $this->actingAs($other)->patchJson("/chat-lists/{$list['id']}", ['name' => 'Mine'])->assertNotFound();
        $this->actingAs($other)->deleteJson("/chat-lists/{$list['id']}")->assertNotFound();

        $this->actingAs($this->me)->deleteJson("/chat-lists/{$list['id']}")->assertOk();
        $this->assertSame(1, ChatList::count());
        $this->assertDatabaseCount('chat_list_items', 0);

        Event::assertDispatched(ChatListsUpdated::class);
    }

    public function test_names_and_list_limits(): void
    {
        $this->actingAs($this->me)->postJson('/chat-lists', ['name' => str_repeat('x', 31)])->assertJsonValidationErrors('name');
        $this->actingAs($this->me)->postJson('/chat-lists', ['name' => '   '])->assertUnprocessable();

        foreach (range(1, ChatList::MAX_PER_USER) as $i) {
            ChatList::query()->create(['user_id' => $this->me->id, 'name' => "List {$i}"]);
        }
        $this->actingAs($this->me)->postJson('/chat-lists', ['name' => 'One more'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'You can have up to 20 lists.');
    }

    private function chat(): Conversation
    {
        $friend = User::factory()->create();
        $conversation = Conversation::factory()->between($this->me, $friend)->create();
        Message::factory()->inConversation($conversation, $friend)->create();

        return $conversation;
    }
}

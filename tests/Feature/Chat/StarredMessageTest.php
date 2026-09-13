<?php

namespace Tests\Feature\Chat;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\StarredMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M5 — Star messages and the starred messages list.
 */
class StarredMessageTest extends TestCase
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

    public function test_starring_is_private_and_shown_in_history(): void
    {
        $message = $this->message($this->friend, 'Wifi password is on the fridge');

        $this->actingAs($this->me)->putJson("/messages/{$message->id}/star")->assertOk()->assertJsonPath('is_starred', true);
        $this->actingAs($this->me)->putJson("/messages/{$message->id}/star")->assertOk(); // idempotent
        $this->assertSame(1, StarredMessage::count());

        $this->actingAs($this->me)->getJson("/conversations/{$this->conversation->id}/messages")->assertJsonPath('data.0.is_starred', true);
        $this->actingAs($this->friend)->getJson("/conversations/{$this->conversation->id}/messages")->assertJsonPath('data.0.is_starred', false);

        $this->actingAs($this->me)->deleteJson("/messages/{$message->id}/star")->assertOk()->assertJsonPath('is_starred', false);
        $this->assertSame(0, StarredMessage::count());
    }

    public function test_starred_list_across_chats_newest_star_first_with_the_other_person(): void
    {
        $other = User::factory()->create(['name' => 'Sara Profile']);
        $otherChat = Conversation::factory()->between($this->me, $other)->create();
        Contact::create(['user_id' => $this->me->id, 'contact_user_id' => $other->id, 'name' => 'Sara Office', 'phone' => '03001112223']);

        $first = $this->message($this->friend, 'first');
        $second = Message::factory()->inConversation($otherChat, $this->me)->create(['message' => 'second']);

        $this->actingAs($this->me)->putJson("/messages/{$first->id}/star");
        $this->actingAs($this->me)->putJson("/messages/{$second->id}/star");

        $this->actingAs($this->me)->getJson('/starred')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.message.id', $second->id)
            ->assertJsonPath('data.0.message.is_mine', true)
            ->assertJsonPath('data.0.message.is_starred', true)
            ->assertJsonPath('data.0.peer.id', $other->id)
            ->assertJsonPath('data.0.peer.saved_name', 'Sara Office')
            ->assertJsonPath('data.1.peer.id', $this->friend->id)
            ->assertJsonPath('has_more', false);

        $this->actingAs($this->friend)->getJson('/starred')->assertJsonCount(0, 'data');
    }

    public function test_deleted_messages_leave_the_list(): void
    {
        $forMe = $this->message($this->friend, 'hide me');
        $forEveryone = $this->message($this->friend, 'remove for all');
        foreach ([$forMe, $forEveryone] as $message) {
            $this->actingAs($this->me)->putJson("/messages/{$message->id}/star")->assertOk();
            $this->actingAs($this->friend)->putJson("/messages/{$message->id}/star")->assertOk();
        }

        $this->actingAs($this->me)->deleteJson("/messages/{$forMe->id}", ['scope' => 'me'])->assertOk();
        $this->actingAs($this->friend)->deleteJson("/messages/{$forEveryone->id}", ['scope' => 'everyone'])->assertOk();

        $this->actingAs($this->me)->getJson('/starred')->assertJsonCount(0, 'data');
        // The friend still sees the message only I hid.
        $this->actingAs($this->friend)->getJson('/starred')->assertJsonCount(1, 'data')->assertJsonPath('data.0.message.id', $forMe->id);
    }

    public function test_pagination_and_access(): void
    {
        $messages = collect(range(1, 32))->map(fn ($i) => $this->message($this->friend, "note {$i}"));
        $messages->each(fn ($message) => StarredMessage::create(['user_id' => $this->me->id, 'message_id' => $message->id]));

        $page = $this->actingAs($this->me)->getJson('/starred')->assertJsonCount(30, 'data')->assertJsonPath('has_more', true);
        $this->actingAs($this->me)->getJson('/starred?before='.$page->json('data.29.star_id'))->assertJsonCount(2, 'data')->assertJsonPath('has_more', false);

        $foreign = Message::factory()->inConversation(
            Conversation::factory()->between(User::factory()->create(), $this->friend)->create(),
            $this->friend,
        )->create();
        $this->actingAs($this->me)->putJson("/messages/{$foreign->id}/star")->assertNotFound();
    }

    private function message(User $sender, string $text): Message
    {
        return Message::factory()->inConversation($this->conversation, $sender)->create(['message' => $text]);
    }
}

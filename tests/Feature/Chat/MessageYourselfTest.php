<?php

namespace Tests\Feature\Chat;

use App\Events\MessageSent;
use App\Events\UserTyping;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * C7 — "Message yourself": notes, links and files kept in a chat with yourself.
 */
class MessageYourselfTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create();
    }

    public function test_opening_a_chat_with_yourself_gives_one_self_chat(): void
    {
        $first = $this->actingAs($this->me)->postJson('/conversations', ['user_id' => $this->me->id])
            ->assertCreated()
            ->assertJsonPath('is_self', true)
            ->assertJsonPath('participant.id', $this->me->id)
            ->json('id');

        $this->actingAs($this->me)->postJson('/conversations', ['user_id' => $this->me->id])
            ->assertOk()
            ->assertJsonPath('id', $first);

        $friend = User::factory()->create();
        $this->actingAs($this->me)->postJson('/conversations', ['user_id' => $friend->id])->assertJsonPath('is_self', false);
        $this->actingAs($friend)->getJson("/conversations/{$first}")->assertNotFound();
    }

    public function test_notes_are_read_at_once_without_notifications_or_unread_counts(): void
    {
        Notification::fake();
        Event::fake([MessageSent::class]);
        $chat = $this->selfChat();

        $this->actingAs($this->me)->postJson("/conversations/{$chat->id}/messages", ['message' => 'Buy milk'])
            ->assertCreated()
            ->assertJsonPath('status', 'seen');

        $this->actingAs($this->me)->getJson('/conversations')
            ->assertJsonCount(1)
            ->assertJsonPath('0.is_self', true)
            ->assertJsonPath('0.unread_count', 0)
            ->assertJsonPath('0.last_message.preview', 'Buy milk');

        $this->actingAs($this->me)->getJson("/conversations/{$chat->id}/messages")->assertJsonPath('data.0.body', 'Buy milk');

        Notification::assertNothingSent();
        // My other tabs and phone still get the note, on one channel only.
        Event::assertDispatched(MessageSent::class, fn (MessageSent $event) => count($event->broadcastOn()) === 1);
    }

    public function test_delete_for_me_hides_a_note(): void
    {
        $chat = $this->selfChat();
        $note = Message::factory()->inConversation($chat, $this->me)->create(['message' => 'Old note']);

        $this->actingAs($this->me)->deleteJson("/messages/{$note->id}", ['scope' => 'me'])->assertOk();

        $this->actingAs($this->me)->getJson("/conversations/{$chat->id}/messages")->assertJsonCount(0, 'data');
        $this->assertTrue($note->fresh()->deleted_for_receiver);
    }

    public function test_no_typing_calls_or_view_once_in_a_self_chat(): void
    {
        Event::fake([UserTyping::class]);
        Storage::fake('chat');
        $chat = $this->selfChat();

        $this->actingAs($this->me)->postJson("/conversations/{$chat->id}/typing", ['typing' => true])->assertSuccessful();
        Event::assertNotDispatched(UserTyping::class);

        $this->actingAs($this->me)->postJson("/conversations/{$chat->id}/calls", ['type' => 'audio', 'client_id' => 'caller-tab-0001'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'You cannot call yourself.');

        $this->actingAs($this->me)
            ->post("/conversations/{$chat->id}/messages", ['attachment' => UploadedFile::fake()->image('photo.jpg', 400, 300), 'view_once' => true], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonMissingPath('view_once');
    }

    public function test_blocking_yourself_is_refused(): void
    {
        $this->actingAs($this->me)->postJson("/users/{$this->me->id}/block")->assertUnprocessable();
    }

    private function selfChat(): Conversation
    {
        return Conversation::factory()->between($this->me, $this->me)->create();
    }
}

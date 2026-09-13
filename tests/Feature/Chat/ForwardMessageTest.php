<?php

namespace Tests\Feature\Chat;

use App\Models\BlockedUser;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * M1 — Forward a message to other chats.
 */
class ForwardMessageTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $friend;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('chat');

        [$this->me, $this->friend] = User::factory()->count(2)->create();
        $this->conversation = Conversation::factory()->between($this->me, $this->friend)->create();
    }

    public function test_a_text_message_is_forwarded_to_several_chats(): void
    {
        $original = Message::factory()->inConversation($this->conversation, $this->friend)->create(['message' => 'Meeting at 5']);
        [$a, $b] = $this->otherChats(2);

        $response = $this->forward($original, [$a->id, $b->id])
            ->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.conversation_id', $a->id)
            ->assertJsonPath('data.0.body', 'Meeting at 5')
            ->assertJsonPath('data.0.forwarded', true)
            ->assertJsonPath('data.0.forwarded_many', false)
            ->assertJsonPath('data.0.is_mine', true);

        $copy = Message::find($response->json('data.1.id'));
        $this->assertSame($this->me->id, $copy->sender_id);
        $this->assertSame($b->otherParticipantId($this->me), $copy->receiver_id);
        $this->assertSame(1, $copy->forward_count);
        $this->assertSame($copy->id, $b->fresh()->last_message_id);
    }

    public function test_forwarding_a_forward_counts_hops_and_shows_many_times(): void
    {
        $original = Message::factory()->inConversation($this->conversation, $this->friend)->create(['forward_count' => 4]);
        [$chat] = $this->otherChats(1);

        $this->forward($original, [$chat->id])->assertJsonPath('data.0.forwarded_many', true);
        $this->assertSame(5, Message::latest('id')->first()->forward_count);
    }

    public function test_attachments_are_copied_so_each_message_keeps_its_file(): void
    {
        $this->actingAs($this->friend)
            ->post("/conversations/{$this->conversation->id}/messages", [
                'attachment' => UploadedFile::fake()->image('beach.jpg', 800, 600),
                'message' => 'Look',
            ], ['Accept' => 'application/json'])
            ->assertCreated();
        $original = Message::sole();
        [$chat] = $this->otherChats(1);

        $copy = Message::find($this->forward($original, [$chat->id])->assertCreated()->json('data.0.id'));

        $this->assertNotSame($original->attachment, $copy->attachment);
        Storage::disk('chat')->assertExists([$copy->attachment, $copy->attachment_meta['thumbnail']]);
        $this->assertSame('Look', $copy->message);
        $this->assertSame($original->attachment_name, $copy->attachment_name);

        // Deleting the original for everyone keeps the forwarded copy's file.
        $this->actingAs($this->friend)->deleteJson("/messages/{$original->id}", ['scope' => 'everyone'])->assertOk();
        Storage::disk('chat')->assertExists($copy->attachment);

        $recipient = User::find($copy->receiver_id);
        $this->actingAs($recipient)->get("/messages/{$copy->id}/attachment")->assertOk();
    }

    public function test_limits_and_permissions(): void
    {
        $original = Message::factory()->inConversation($this->conversation, $this->friend)->create();
        $chats = $this->otherChats(6);

        $this->forward($original, $chats->pluck('id')->all())->assertJsonValidationErrors('conversation_ids');
        $this->forward($original, [])->assertJsonValidationErrors('conversation_ids');

        // A chat that isn't mine.
        $strangers = Conversation::factory()->between(User::factory()->create(), User::factory()->create())->create();
        $this->forward($original, [$chats[0]->id, $strangers->id])->assertJsonValidationErrors('conversation_ids');

        // A chat where I am blocked.
        BlockedUser::create(['user_id' => $chats[1]->otherParticipantId($this->me), 'blocked_user_id' => $this->me->id]);
        $this->forward($original, [$chats[1]->id])
            ->assertJsonValidationErrors(['conversation_ids' => 'You can no longer send messages to this user.']);

        // A message from someone else's chat.
        $foreign = Message::factory()->inConversation($strangers, User::find($strangers->user_one_id))->create();
        $this->forward($foreign, [$chats[0]->id])->assertNotFound();

        // Deleted messages and call history.
        $original->forceFill(['deleted_for_everyone' => true])->save();
        $this->forward($original, [$chats[0]->id])->assertNotFound();
        $call = Message::factory()->inConversation($this->conversation, $this->friend)->create(['message_type' => Message::TYPE_CALL, 'message' => null]);
        $this->forward($call, [$chats[0]->id])->assertForbidden();

        $this->assertSame(0, Message::where('forward_count', '>', 0)->count());
    }

    /* ------------------------------------------------------------------ */

    private function forward(Message $message, array $conversationIds)
    {
        return $this->actingAs($this->me)->postJson("/messages/{$message->id}/forward", ['conversation_ids' => $conversationIds]);
    }

    private function otherChats(int $count)
    {
        return collect(range(1, $count))->map(
            fn () => Conversation::factory()->between($this->me, User::factory()->create())->create()
        );
    }
}

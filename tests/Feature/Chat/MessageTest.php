<?php

namespace Tests\Feature\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageTest extends TestCase
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

    public function test_participant_can_send_a_text_message(): void
    {
        $response = $this->actingAs($this->me)
            ->postJson("/conversations/{$this->conversation->id}/messages", [
                'message' => "  Hello brother 👋\u{202E}  ",
                'client_id' => 'abc-123',
            ]);

        $response->assertCreated()
            ->assertJsonPath('body', 'Hello brother 👋')
            ->assertJsonPath('is_mine', true)
            ->assertJsonPath('status', 'sent')
            ->assertJsonPath('type', 'text')
            ->assertJsonPath('client_id', 'abc-123');

        $message = Message::sole();
        $this->assertSame($this->me->id, $message->sender_id);
        $this->assertSame($this->friend->id, $message->receiver_id);
        $this->assertNotNull($message->sent_at);
        $this->assertNull($message->delivered_at);
        $this->assertSame($message->id, $this->conversation->fresh()->last_message_id);
    }

    public function test_message_text_is_stored_raw_and_never_interpreted(): void
    {
        $payload = '<script>alert("xss")</script>';

        $this->actingAs($this->me)
            ->postJson("/conversations/{$this->conversation->id}/messages", ['message' => $payload])
            ->assertCreated()
            ->assertJsonPath('body', $payload);

        // The dashboard never renders message bodies server-side.
        $this->actingAs($this->friend)->get("/chat/{$this->conversation->id}")->assertOk()->assertDontSee($payload, false);
    }

    public function test_empty_and_oversized_messages_are_rejected(): void
    {
        $url = "/conversations/{$this->conversation->id}/messages";

        $this->actingAs($this->me)->postJson($url, ['message' => '   '])->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->actingAs($this->me)->postJson($url, ['message' => str_repeat('a', config('chat.max_message_length') + 1)])
            ->assertUnprocessable();
    }

    public function test_reply_must_reference_a_message_in_the_same_conversation(): void
    {
        $other = Conversation::factory()->between($this->me, User::factory()->create())->create();
        $foreign = Message::factory()->inConversation($other, $this->me)->create();
        $local = Message::factory()->inConversation($this->conversation, $this->friend)->create();

        $url = "/conversations/{$this->conversation->id}/messages";

        $this->actingAs($this->me)->postJson($url, ['message' => 'hi', 'reply_to_id' => $foreign->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reply_to_id');

        $this->actingAs($this->me)->postJson($url, ['message' => 'hi', 'reply_to_id' => $local->id])
            ->assertCreated()
            ->assertJsonPath('reply_to.id', $local->id);
    }

    public function test_history_is_paginated_from_newest_with_cursor(): void
    {
        config(['chat.messages_per_page' => 30]);
        Message::factory()->inConversation($this->conversation, $this->friend)->count(45)->create();
        $ids = Message::orderBy('id')->pluck('id');

        $first = $this->actingAs($this->me)->getJson("/conversations/{$this->conversation->id}/messages")
            ->assertOk()
            ->assertJsonPath('has_more', true)
            ->assertJsonCount(30, 'data');

        // Oldest → newest within the page, ending with the latest message.
        $this->assertSame($ids->slice(15)->values()->all(), array_column($first->json('data'), 'id'));

        $older = $this->actingAs($this->me)
            ->getJson("/conversations/{$this->conversation->id}/messages?before=".$first->json('data.0.id'))
            ->assertOk()
            ->assertJsonPath('has_more', false)
            ->assertJsonCount(15, 'data');

        $this->assertSame($ids->take(15)->values()->all(), array_column($older->json('data'), 'id'));
    }

    public function test_messages_deleted_for_me_are_hidden_only_for_me(): void
    {
        $mine = Message::factory()->inConversation($this->conversation, $this->me)->create();
        $mine->forceFill(['deleted_for_sender' => true])->save();

        $this->actingAs($this->me)->getJson("/conversations/{$this->conversation->id}/messages")->assertJsonCount(0, 'data');
        $this->actingAs($this->friend)->getJson("/conversations/{$this->conversation->id}/messages")->assertJsonCount(1, 'data');
    }

    public function test_sending_is_rate_limited(): void
    {
        $url = "/conversations/{$this->conversation->id}/messages";

        for ($i = 0; $i < 60; $i++) {
            $this->actingAs($this->me)->postJson($url, ['message' => "msg {$i}"])->assertCreated();
        }

        $this->actingAs($this->me)->postJson($url, ['message' => 'one too many'])->assertTooManyRequests();
    }

    public function test_guests_cannot_use_chat_endpoints(): void
    {
        $this->getJson('/conversations')->assertUnauthorized();
        $this->getJson("/conversations/{$this->conversation->id}/messages")->assertUnauthorized();
        $this->postJson("/conversations/{$this->conversation->id}/messages", ['message' => 'x'])->assertUnauthorized();
        $this->getJson('/users/search?q=a')->assertUnauthorized();
    }
}

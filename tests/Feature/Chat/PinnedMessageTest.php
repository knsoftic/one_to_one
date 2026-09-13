<?php

namespace Tests\Feature\Chat;

use App\Events\ConversationPinsUpdated;
use App\Models\BlockedUser;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PinnedMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * M6 — Pin messages to the top of a chat.
 */
class PinnedMessageTest extends TestCase
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

    public function test_pins_are_shared_newest_first_and_expire(): void
    {
        Event::fake([ConversationPinsUpdated::class]);
        $message = $this->message($this->friend, 'Party at *8pm*');

        $this->pin($message, 86400)
            ->assertOk()
            ->assertJsonPath('pinned_messages.0.message_id', $message->id)
            ->assertJsonPath('pinned_messages.0.preview', 'Party at 8pm')
            ->assertJsonPath('pinned_messages.0.pinned_by_me', true);

        Event::assertDispatched(ConversationPinsUpdated::class, fn ($event) => $event->broadcastWith() === ['conversation_id' => $this->conversation->id]);

        $this->actingAs($this->friend)->getJson("/conversations/{$this->conversation->id}")
            ->assertJsonPath('pinned_messages.0.message_id', $message->id)
            ->assertJsonPath('pinned_messages.0.pinned_by_me', false);

        $this->travel(86401)->seconds();
        $this->actingAs($this->me)->getJson("/conversations/{$this->conversation->id}")->assertJsonCount(0, 'pinned_messages');
    }

    public function test_a_fourth_pin_replaces_the_oldest_and_repinning_moves_it_up(): void
    {
        $messages = collect(range(1, 4))->map(fn ($i) => $this->message($this->me, "note {$i}"));

        foreach ($messages->take(3) as $message) {
            $this->pin($message)->assertOk();
            $this->travel(1)->seconds();
        }
        $this->pin($messages[0])->assertOk(); // re-pin the first: now newest
        $this->travel(1)->seconds();
        $response = $this->pin($messages[3])->assertOk();

        $this->assertSame(
            [$messages[3]->id, $messages[0]->id, $messages[2]->id],
            array_column($response->json('pinned_messages'), 'message_id'),
        );
        $this->assertSame(3, PinnedMessage::count());
    }

    public function test_unpin_and_deleted_messages(): void
    {
        $a = $this->message($this->friend, 'a');
        $b = $this->message($this->friend, 'b');
        $this->pin($a)->assertOk();
        $this->pin($b)->assertOk();

        $this->actingAs($this->friend)->deleteJson("/messages/{$a->id}/pin")->assertOk()->assertJsonCount(1, 'pinned_messages');

        // Hidden for me: I no longer see the pin, the friend still does.
        $this->actingAs($this->me)->deleteJson("/messages/{$b->id}", ['scope' => 'me'])->assertOk();
        $this->actingAs($this->me)->getJson("/conversations/{$this->conversation->id}")->assertJsonCount(0, 'pinned_messages');
        $this->actingAs($this->friend)->getJson("/conversations/{$this->conversation->id}")->assertJsonCount(1, 'pinned_messages');

        // Deleted for everyone removes the pin.
        $this->actingAs($this->friend)->deleteJson("/messages/{$b->id}", ['scope' => 'everyone'])->assertOk();
        $this->assertSame(0, PinnedMessage::count());
    }

    public function test_validation_and_permissions(): void
    {
        $message = $this->message($this->friend, 'x');

        $this->pin($message, 3600)->assertJsonValidationErrors('duration');
        $this->actingAs(User::factory()->create())->putJson("/messages/{$message->id}/pin", ['duration' => 86400])->assertNotFound();

        $call = $this->message($this->friend, null, ['message_type' => Message::TYPE_CALL]);
        $this->pin($call)->assertForbidden();

        BlockedUser::create(['user_id' => $this->friend->id, 'blocked_user_id' => $this->me->id]);
        $this->pin($message)->assertForbidden();

        $this->assertSame(0, PinnedMessage::count());
    }

    private function message(User $sender, ?string $text, array $attributes = []): Message
    {
        return Message::factory()->inConversation($this->conversation, $sender)->create(['message' => $text] + $attributes);
    }

    private function pin(Message $message, int $duration = 604800)
    {
        return $this->actingAs($this->me)->putJson("/messages/{$message->id}/pin", ['duration' => $duration]);
    }
}

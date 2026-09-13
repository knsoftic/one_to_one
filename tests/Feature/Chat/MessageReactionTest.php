<?php

namespace Tests\Feature\Chat;

use App\Events\MessageUpdated;
use App\Models\BlockedUser;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * M2 — Emoji reactions on messages.
 */
class MessageReactionTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $friend;

    private Conversation $conversation;

    private Message $message;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->me, $this->friend] = User::factory()->count(2)->create();
        $this->conversation = Conversation::factory()->between($this->me, $this->friend)->create();
        $this->message = Message::factory()->inConversation($this->conversation, $this->friend)->create(['message' => 'Good news!']);
    }

    public function test_reacting_adds_replaces_and_removes_one_reaction_per_person(): void
    {
        Event::fake([MessageUpdated::class]);

        $this->react('👍')->assertOk()->assertJsonPath('reactions', [['emoji' => '👍', 'count' => 1, 'user_ids' => [$this->me->id]]]);
        $this->react('❤️', $this->friend)->assertOk();

        // Choosing another emoji replaces mine.
        $this->react('😂')->assertOk()->assertJsonCount(2, 'reactions');
        $this->assertSame(2, MessageReaction::count());
        $this->assertSame('😂', MessageReaction::where('user_id', $this->me->id)->value('emoji'));

        // Same emoji from both people is grouped (in the order people first reacted).
        $this->react('❤️')->assertJsonPath('reactions', [['emoji' => '❤️', 'count' => 2, 'user_ids' => [$this->me->id, $this->friend->id]]]);

        $this->actingAs($this->me)->deleteJson("/messages/{$this->message->id}/reaction")
            ->assertOk()
            ->assertJsonPath('reactions', [['emoji' => '❤️', 'count' => 1, 'user_ids' => [$this->friend->id]]]);

        Event::assertDispatched(MessageUpdated::class, fn (MessageUpdated $event) => $event->message->is($this->message)
            && $event->broadcastWith()['message']['reactions'][0]['emoji'] === '❤️');
    }

    public function test_reactions_are_part_of_history_and_polling_sync(): void
    {
        $this->react('🙏')->assertOk();

        $this->actingAs($this->friend)->getJson("/conversations/{$this->conversation->id}/messages")
            ->assertJsonPath('data.0.reactions.0.emoji', '🙏');

        $this->actingAs($this->friend)
            ->getJson('/chat/sync?since='.urlencode(now()->subMinute()->toIso8601String()))
            ->assertJsonPath('messages.0.reactions.0.emoji', '🙏');
    }

    public function test_emoji_validation_accepts_real_emoji_only(): void
    {
        foreach (['👍', '❤️', '👍🏽', '🇵🇰', '👨‍👩‍👧', '🫶'] as $emoji) {
            $this->react($emoji)->assertOk();
        }

        foreach (['a', '👍 nice', '<b>', '12', str_repeat('😀', 20), ''] as $invalid) {
            $this->react($invalid)->assertJsonValidationErrors('emoji');
        }
    }

    public function test_permissions(): void
    {
        $stranger = User::factory()->create();
        $this->react('👍', $stranger)->assertNotFound();

        $call = Message::factory()->inConversation($this->conversation, $this->friend)->create(['message_type' => Message::TYPE_CALL, 'message' => null]);
        $this->actingAs($this->me)->putJson("/messages/{$call->id}/reaction", ['emoji' => '👍'])->assertForbidden();

        BlockedUser::create(['user_id' => $this->me->id, 'blocked_user_id' => $this->friend->id]);
        $this->react('👍')->assertForbidden();

        $this->assertSame(0, MessageReaction::count());
    }

    public function test_deleting_for_everyone_removes_reactions(): void
    {
        $this->react('👍')->assertOk();

        $this->actingAs($this->friend)->deleteJson("/messages/{$this->message->id}", ['scope' => 'everyone'])
            ->assertOk()
            ->assertJsonPath('message.reactions', []);

        $this->assertSame(0, MessageReaction::count());
    }

    private function react(string $emoji, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->me)->putJson("/messages/{$this->message->id}/reaction", ['emoji' => $emoji]);
    }
}

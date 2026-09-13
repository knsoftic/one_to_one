<?php

namespace Tests\Feature\Chat;

use App\Events\MessageUpdated;
use App\Models\BlockedUser;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PollVote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * M20 — Polls.
 */
class PollTest extends TestCase
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

    public function test_a_poll_is_created_with_numbered_options(): void
    {
        $this->poll(['question' => '  Dinner   tonight? ', 'options' => ['Biryani', ' Karahi ', 'Pizza'], 'multiple' => true])
            ->assertCreated()
            ->assertJsonPath('type', 'poll')
            ->assertJsonPath('poll.question', 'Dinner tonight?')
            ->assertJsonPath('poll.multiple', true)
            ->assertJsonPath('poll.options.1', ['id' => 2, 'text' => 'Karahi', 'count' => 0, 'voter_ids' => []])
            ->assertJsonPath('poll.total_voters', 0);

        $this->assertSame('📊 Poll: Dinner tonight?', Message::sole()->preview());
    }

    public function test_invalid_polls_are_refused(): void
    {
        $this->poll(['question' => 'Q', 'options' => ['Only one']])->assertJsonValidationErrors('poll.options');
        $this->poll(['question' => 'Q', 'options' => ['Yes', 'yes']])->assertJsonValidationErrors('poll.options.1');
        $this->poll(['question' => 'Q', 'options' => array_map(fn ($i) => "Option {$i}", range(1, 13))])->assertJsonValidationErrors('poll.options');
        $this->poll(['question' => str_repeat('q', 256), 'options' => ['A', 'B']])->assertJsonValidationErrors('poll.question');
        $this->poll(['options' => ['A', 'B']])->assertJsonValidationErrors('poll.question');

        $this->assertSame(0, Message::count());
    }

    public function test_single_answer_votes_replace_and_can_be_removed(): void
    {
        Event::fake([MessageUpdated::class]);
        $id = $this->poll(['question' => 'Meet at?', 'options' => ['5 pm', '6 pm']])->json('id');

        $this->vote($this->friend, $id, [1])->assertOk()->assertJsonPath('poll.options.0.voter_ids', [$this->friend->id]);
        $this->vote($this->me, $id, [1])->assertOk()->assertJsonPath('poll.options.0.count', 2)->assertJsonPath('poll.total_voters', 2);
        $this->vote($this->friend, $id, [2])->assertOk()
            ->assertJsonPath('poll.options.0.voter_ids', [$this->me->id])
            ->assertJsonPath('poll.options.1.voter_ids', [$this->friend->id]);

        $this->vote($this->friend, $id, [1, 2])->assertUnprocessable()->assertJsonPath('message', 'Only one answer can be chosen in this poll.');
        $this->vote($this->friend, $id, [3])->assertUnprocessable();

        $this->vote($this->friend, $id, [])->assertOk()->assertJsonPath('poll.total_voters', 1);

        Event::assertDispatched(MessageUpdated::class, fn (MessageUpdated $event) => isset($event->broadcastWith()['message']['poll']));
    }

    public function test_multiple_answer_polls_and_who_can_vote(): void
    {
        $id = $this->poll(['question' => 'Bring?', 'options' => ['Drinks', 'Cake', 'Plates'], 'multiple' => true])->json('id');

        $this->vote($this->friend, $id, [1, 3])->assertOk()
            ->assertJsonPath('poll.options.0.count', 1)
            ->assertJsonPath('poll.options.2.count', 1)
            ->assertJsonPath('poll.total_voters', 1);

        $this->vote(User::factory()->create(), $id, [1])->assertNotFound();

        BlockedUser::query()->create(['user_id' => $this->me->id, 'blocked_user_id' => $this->friend->id]);
        $this->vote($this->friend, $id, [2])->assertForbidden();

        $text = Message::factory()->inConversation($this->conversation, $this->me)->create();
        $this->vote($this->me, $text->id, [1])->assertForbidden();
    }

    public function test_history_forwarding_and_deleting_polls(): void
    {
        $id = $this->poll(['question' => 'Trip?', 'options' => ['Murree', 'Naran']])->json('id');
        $this->vote($this->friend, $id, [2]);

        $this->actingAs($this->me)->getJson("/conversations/{$this->conversation->id}/messages")
            ->assertJsonPath('data.0.poll.options.1.count', 1);

        $other = Conversation::factory()->between($this->me, User::factory()->create())->create();
        $this->actingAs($this->me)->postJson("/messages/{$id}/forward", ['conversation_ids' => [$other->id]])
            ->assertCreated()
            ->assertJsonPath('data.0.poll.question', 'Trip?')
            ->assertJsonPath('data.0.poll.total_voters', 0);

        $this->actingAs($this->me)->deleteJson("/messages/{$id}", ['scope' => 'everyone'])->assertOk();
        $this->assertSame(0, PollVote::count());
    }

    private function poll(array $poll)
    {
        return $this->actingAs($this->me)->postJson("/conversations/{$this->conversation->id}/messages", ['poll' => $poll]);
    }

    private function vote(User $user, int $id, array $options)
    {
        return $this->actingAs($user)->putJson("/messages/{$id}/vote", ['options' => $options]);
    }
}

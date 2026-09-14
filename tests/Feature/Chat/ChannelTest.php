<?php

namespace Tests\Feature\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * G11 — Channels.
 */
class ChannelTest extends TestCase
{
    use RefreshDatabase;

    private User $ayesha;

    private User $bilal;

    private User $sara;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->ayesha, $this->bilal, $this->sara] = [
            User::factory()->create(['name' => 'Ayesha']),
            User::factory()->create(['name' => 'Bilal']),
            User::factory()->create(['name' => 'Sara']),
        ];
    }

    public function test_anyone_finds_and_previews_a_channel_and_followers_read_its_updates(): void
    {
        $channel = $this->actingAs($this->ayesha)->postJson('/channels', ['name' => 'Lahore Weather', 'description' => 'Daily forecast and rain alerts'])
            ->assertCreated()
            ->assertJsonPath('type', 'channel')
            ->assertJsonPath('channel.name', 'Lahore Weather')
            ->assertJsonPath('channel.is_admin', true)
            ->assertJsonPath('channel.can_send', true)
            ->assertJsonPath('channel.followers_count', 0)
            ->assertJsonPath('last_message.system.text', 'Channel created')
            ->json('id');
        $this->actingAs($this->ayesha)->postJson("/conversations/{$channel}/messages", ['message' => 'Rain expected after 4 pm ☔'])->assertCreated();

        // Found by name or description; strangers see the latest updates before following.
        $this->actingAs($this->bilal)->getJson('/channels?q=rain')->assertOk()->assertJsonPath('data.0.id', $channel)->assertJsonPath('data.0.is_following', false);
        $this->actingAs($this->bilal)->getJson('/channels?q=cricket')->assertJsonCount(0, 'data');
        $this->actingAs($this->bilal)->getJson("/channels/{$channel}")
            ->assertOk()
            ->assertJsonPath('name', 'Lahore Weather')
            ->assertJsonCount(1, 'updates')
            ->assertJsonPath('updates.0.preview', 'Rain expected after 4 pm ☔');
        $this->actingAs($this->bilal)->getJson("/conversations/{$channel}/messages")->assertNotFound();
        $this->actingAs($this->bilal)->getJson("/channels/{$this->directChat()->id}")->assertNotFound();

        // Following shows the whole channel; old updates are not unread.
        $this->actingAs($this->bilal)->postJson("/channels/{$channel}/follow")
            ->assertOk()
            ->assertJsonPath('channel.is_following', true)
            ->assertJsonPath('channel.is_admin', false)
            ->assertJsonPath('channel.can_send', false)
            ->assertJsonPath('channel.followers_count', 1)
            ->assertJsonPath('unread_count', 0);
        $this->actingAs($this->bilal)->getJson("/conversations/{$channel}/messages")->assertOk()->assertJsonCount(2, 'data');

        // Only admins post; updates don't say who wrote them and don't notify.
        $this->actingAs($this->bilal)->postJson("/conversations/{$channel}/messages", ['message' => 'hi'])
            ->assertForbidden()->assertJsonPath('message', 'Only channel admins can post updates.');
        $this->actingAs($this->ayesha)->postJson("/conversations/{$channel}/messages", ['message' => 'Heatwave warning for Sunday'])->assertCreated();
        $this->actingAs($this->bilal)->getJson('/conversations')
            ->assertJsonPath('0.id', $channel)
            ->assertJsonPath('0.type', 'channel')
            ->assertJsonPath('0.unread_count', 1)
            ->assertJsonPath('0.last_message.sender_name', null);
        $this->assertSame(0, $this->bilal->notifications()->count());
        $messages = $this->actingAs($this->bilal)->getJson("/conversations/{$channel}/messages")->json('data');
        $this->assertArrayNotHasKey('sender_name', end($messages));

        $this->actingAs($this->bilal)->postJson("/conversations/{$channel}/seen")->assertOk();
        $this->actingAs($this->bilal)->getJson('/conversations')->assertJsonPath('0.unread_count', 0);

        // No calls, typing or read receipts in a channel.
        $this->actingAs($this->ayesha)->postJson("/conversations/{$channel}/calls", ['type' => 'audio'])->assertStatus(422);
        $update = Message::query()->where('conversation_id', $channel)->latest('id')->value('id');
        $this->actingAs($this->ayesha)->getJson("/messages/{$update}/receipts")->assertNotFound();

        $this->actingAs($this->sara)->get('/channel/'.Conversation::find($channel)->invite_token)
            ->assertOk()->assertViewHas('chatConfig', fn (array $config) => $config['channelInvite'] === ['valid' => true, 'id' => $channel]);
    }

    public function test_followers_react_and_vote_without_seeing_each_other(): void
    {
        $channel = $this->channel();
        $this->follow($channel, $this->bilal, $this->sara);
        $update = $this->actingAs($this->ayesha)->postJson("/conversations/{$channel}/messages", ['message' => 'New season starts Monday'])->json('id');
        $poll = $this->actingAs($this->ayesha)->postJson("/conversations/{$channel}/messages", ['poll' => ['question' => 'Best day?', 'options' => ['Sat', 'Sun']]])->json('id');

        $this->actingAs($this->bilal)->putJson("/messages/{$update}/reaction", ['emoji' => '🔥'])->assertOk();
        $this->actingAs($this->sara)->putJson("/messages/{$update}/reaction", ['emoji' => '🔥'])->assertOk()
            ->assertJsonPath('reactions.0.count', 2)
            ->assertJsonPath('reactions.0.user_ids', [$this->sara->id]);
        $this->actingAs($this->bilal)->putJson("/messages/{$poll}/vote", ['options' => [2]])->assertOk();
        $this->actingAs($this->sara)->putJson("/messages/{$poll}/vote", ['options' => [2]])->assertOk()
            ->assertJsonPath('poll.options.1.count', 2)
            ->assertJsonPath('poll.options.1.voter_ids', [$this->sara->id]);

        $history = collect($this->actingAs($this->ayesha)->getJson("/conversations/{$channel}/messages")->json('data'))->keyBy('id');
        $this->assertSame([['emoji' => '🔥', 'count' => 2, 'user_ids' => []]], $history[$update]['reactions']);
        $this->assertSame([], $history[$poll]['poll']['options'][1]['voter_ids']);

        // Pinning is for admins.
        $this->actingAs($this->bilal)->putJson("/messages/{$update}/pin", ['duration' => 86400])->assertForbidden();
    }

    public function test_unfollowing_editing_and_deleting_a_channel(): void
    {
        $channel = $this->channel();
        $this->follow($channel, $this->bilal);

        $this->actingAs($this->bilal)->postJson("/channels/{$channel}", ['name' => 'Mine'])->assertForbidden();
        $this->actingAs($this->ayesha)->postJson("/channels/{$channel}", ['name' => 'PSL Updates', 'description' => 'Scores'])
            ->assertOk()->assertJsonPath('channel.name', 'PSL Updates');

        $this->actingAs($this->bilal)->deleteJson("/conversations/{$channel}")->assertUnprocessable();
        $this->actingAs($this->ayesha)->deleteJson("/channels/{$channel}/follow")->assertUnprocessable();

        $this->actingAs($this->bilal)->deleteJson("/channels/{$channel}/follow")->assertOk();
        $this->actingAs($this->bilal)->getJson('/conversations')->assertJsonCount(0);
        $this->actingAs($this->bilal)->getJson("/conversations/{$channel}")->assertNotFound();
        $this->actingAs($this->bilal)->getJson('/channels')->assertJsonPath('data.0.followers_count', 0);

        $this->follow($channel, $this->sara);
        $this->actingAs($this->sara)->deleteJson("/channels/{$channel}")->assertForbidden();
        $this->actingAs($this->ayesha)->deleteJson("/channels/{$channel}")->assertOk();
        $this->assertNull(Conversation::find($channel));
        $this->actingAs($this->sara)->getJson('/conversations')->assertJsonCount(0);
        $this->actingAs($this->sara)->getJson("/channels/{$channel}")->assertNotFound();
    }

    private function channel(): int
    {
        return $this->actingAs($this->ayesha)->postJson('/channels', ['name' => 'Cricket Club'])->assertCreated()->json('id');
    }

    private function follow(int $channel, User ...$users): void
    {
        foreach ($users as $user) {
            $this->actingAs($user)->postJson("/channels/{$channel}/follow")->assertOk();
        }
    }

    private function directChat(): Conversation
    {
        return Conversation::factory()->between($this->ayesha, $this->bilal)->create();
    }
}

<?php

namespace Tests\Feature\Chat;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * G7 — Reply privately to a group message.
 */
class GroupReplyPrivatelyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_group_message_is_quoted_in_the_private_chat_with_its_writer(): void
    {
        [$ayesha, $bilal, $sara] = [User::factory()->create(['name' => 'Ayesha']), User::factory()->create(['name' => 'Bilal']), User::factory()->create(['name' => 'Sara'])];
        $group = Conversation::findOrFail($this->actingAs($ayesha)->postJson('/groups', ['name' => 'Family', 'member_ids' => [$bilal->id, $sara->id]])->json('id'));
        $question = $this->actingAs($sara)->postJson("/conversations/{$group->id}/messages", ['message' => 'Who is bringing the cake?'])->json('id');

        $private = $this->actingAs($bilal)->postJson('/conversations', ['user_id' => $sara->id])->json('id');

        $this->actingAs($bilal)->postJson("/conversations/{$private}/messages", ['message' => 'Me, but keep it secret', 'reply_to_id' => $question])
            ->assertCreated()
            ->assertJsonPath('reply_to.id', $question)
            ->assertJsonPath('reply_to.conversation_id', $group->id)
            ->assertJsonPath('reply_to.group_name', 'Family')
            ->assertJsonPath('reply_to.preview', 'Who is bringing the cake?');

        // Sara sees the quote in her chat with Bilal; Ayesha sees nothing of it.
        $this->actingAs($sara)->getJson("/conversations/{$private}/messages")->assertJsonPath('data.0.reply_to.group_name', 'Family');
        $this->actingAs($ayesha)->getJson("/conversations/{$private}")->assertNotFound();
    }

    public function test_only_the_writers_own_chat_and_messages_the_replier_could_see(): void
    {
        [$ayesha, $bilal, $sara] = [User::factory()->create(), User::factory()->create(), User::factory()->create()];
        $group = Conversation::findOrFail($this->actingAs($ayesha)->postJson('/groups', ['name' => 'Family', 'member_ids' => [$bilal->id, $sara->id]])->json('id'));
        $question = $this->actingAs($sara)->postJson("/conversations/{$group->id}/messages", ['message' => 'Secret'])->json('id');

        // Not in a chat with someone else.
        $withAyesha = $this->actingAs($bilal)->postJson('/conversations', ['user_id' => $ayesha->id])->json('id');
        $this->actingAs($bilal)->postJson("/conversations/{$withAyesha}/messages", ['message' => 'Look', 'reply_to_id' => $question])
            ->assertJsonValidationErrors(['reply_to_id' => 'The message you are replying to is no longer available.']);

        // Not by someone who was never in the group.
        $stranger = User::factory()->create();
        $withSara = $this->actingAs($stranger)->postJson('/conversations', ['user_id' => $sara->id])->json('id');
        $this->actingAs($stranger)->postJson("/conversations/{$withSara}/messages", ['message' => 'Hmm', 'reply_to_id' => $question])
            ->assertJsonValidationErrors('reply_to_id');

        // Not to your own group message, and not to a message deleted for you.
        $mine = $this->actingAs($bilal)->postJson("/conversations/{$group->id}/messages", ['message' => 'Mine'])->json('id');
        $withSaraFromBilal = $this->actingAs($bilal)->postJson('/conversations', ['user_id' => $sara->id])->json('id');
        $this->actingAs($bilal)->postJson("/conversations/{$withSaraFromBilal}/messages", ['message' => 'x', 'reply_to_id' => $mine])->assertJsonValidationErrors('reply_to_id');
        $this->actingAs($bilal)->deleteJson("/messages/{$question}", ['scope' => 'me'])->assertOk();
        $this->actingAs($bilal)->postJson("/conversations/{$withSaraFromBilal}/messages", ['message' => 'x', 'reply_to_id' => $question])->assertJsonValidationErrors('reply_to_id');
    }
}

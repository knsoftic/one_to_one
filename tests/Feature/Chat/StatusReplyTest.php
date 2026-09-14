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
 * S4 — Reply to and react to a status: it arrives in the one-to-one chat.
 */
class StatusReplyTest extends TestCase
{
    use RefreshDatabase, StatusTestHelpers;

    public function test_a_reply_arrives_in_the_chat_quoting_the_update(): void
    {
        Storage::fake('chat');
        [$ayesha, $bilal, $stranger] = User::factory()->count(3)->create();
        $this->saveContact($ayesha, $bilal);
        $photo = $this->actingAs($ayesha)->post('/statuses', ['attachment' => UploadedFile::fake()->image('hunza.jpg', 1000, 700), 'caption' => 'Hunza valley'], ['Accept' => 'application/json'])->json('id');

        $reply = $this->actingAs($bilal)->postJson("/statuses/{$photo}/reply", ['message' => 'Wow, so beautiful!'])
            ->assertCreated()
            ->assertJsonPath('body', 'Wow, so beautiful!')
            ->assertJsonPath('status_quote.id', $photo)
            ->assertJsonPath('status_quote.owner_id', $ayesha->id)
            ->assertJsonPath('status_quote.type', 'image')
            ->assertJsonPath('status_quote.text', 'Hunza valley')
            ->assertJsonPath('status_quote.available', true)
            ->assertJsonPath('status_quote.reaction', false)
            ->json();
        $this->assertNotNull($reply['status_quote']['thumbnail_url']);
        $this->actingAs($ayesha)->get($reply['status_quote']['thumbnail_url'])->assertOk();

        $chat = Conversation::query()->between($ayesha, $bilal)->sole();
        $this->assertSame($chat->id, $reply['conversation_id']);
        $this->actingAs($ayesha)->getJson('/conversations')->assertJsonPath('0.last_message.preview', 'Wow, so beautiful!')->assertJsonPath('0.unread_count', 1);

        $this->actingAs($ayesha)->postJson("/statuses/{$photo}/reply", ['message' => 'me'])->assertUnprocessable();
        $this->actingAs($stranger)->postJson("/statuses/{$photo}/reply", ['message' => 'hi'])->assertNotFound();

        // After the update is gone the quote stays, without its picture.
        $this->travel(25)->hours();
        $this->actingAs($ayesha)->getJson("/conversations/{$chat->id}/messages")
            ->assertJsonPath('data.0.status_quote.available', false)
            ->assertJsonPath('data.0.status_quote.thumbnail_url', null)
            ->assertJsonPath('data.0.status_quote.text', 'Hunza valley');
        $this->actingAs($bilal)->postJson("/statuses/{$photo}/reply", ['message' => 'late'])->assertNotFound();
    }

    public function test_reactions_are_sent_once_per_emoji_and_shown_to_the_owner(): void
    {
        [$ayesha, $bilal] = User::factory()->count(2)->create();
        $this->chatBetween($bilal, $ayesha);
        $id = $this->textStatus($ayesha, 'New job! 🎉');

        $this->actingAs($bilal)->postJson("/statuses/{$id}/react", ['emoji' => '❤️'])
            ->assertOk()
            ->assertJsonPath('reaction', '❤️')
            ->assertJsonPath('message.body', '❤️')
            ->assertJsonPath('message.status_quote.reaction', true)
            ->assertJsonPath('message.status_quote.text', 'New job! 🎉');
        $this->actingAs($bilal)->postJson("/statuses/{$id}/react", ['emoji' => '❤️'])->assertOk()->assertJsonPath('message', null);
        $this->actingAs($bilal)->postJson("/statuses/{$id}/react", ['emoji' => '🎉'])->assertOk()->assertJsonPath('message.body', '🎉');
        $this->actingAs($bilal)->postJson("/statuses/{$id}/react", ['emoji' => 'hello'])->assertJsonValidationErrors('emoji');

        $this->assertSame('Reacted 🎉 to a status', Message::query()->latest('id')->first()->preview());
        $this->actingAs($ayesha)->getJson("/statuses/{$id}/viewers")->assertJsonPath('data.0.reaction', '🎉');
        $this->actingAs($ayesha)->getJson('/statuses')->assertJsonPath('mine.0.views_count', 1);

        // Blocked people can't reply or react.
        BlockedUser::create(['user_id' => $ayesha->id, 'blocked_user_id' => $bilal->id]);
        $this->actingAs($bilal)->postJson("/statuses/{$id}/react", ['emoji' => '👍'])->assertNotFound();
    }
}

<?php

namespace Tests\Feature\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M3 — Search inside a chat.
 */
class MessageSearchTest extends TestCase
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

    public function test_finds_text_and_file_names_newest_first(): void
    {
        $old = $this->message($this->friend, 'The invoice is attached');
        $this->message($this->me, 'Nothing to see here');
        $doc = $this->message($this->friend, null, ['message_type' => Message::TYPE_DOCUMENT, 'attachment' => 'x.pdf', 'attachment_name' => 'Invoice-March.pdf']);
        $new = $this->message($this->me, 'Did you pay the INVOICE?');

        $this->search('invoice')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', $new->id)
            ->assertJsonPath('data.0.is_mine', true)
            ->assertJsonPath('data.1.id', $doc->id)
            ->assertJsonPath('data.1.preview', '📄 Invoice-March.pdf')
            ->assertJsonPath('data.2.id', $old->id);
    }

    public function test_hidden_deleted_and_other_chats_are_excluded(): void
    {
        $this->message($this->friend, 'secret plan', ['deleted_for_receiver' => true]);
        $this->message($this->me, 'secret plan', ['deleted_for_everyone' => true, 'message' => null]);
        $other = Conversation::factory()->between($this->me, User::factory()->create())->create();
        Message::factory()->inConversation($other, $this->me)->create(['message' => 'secret plan']);
        $visible = $this->message($this->me, 'my secret plan');

        $this->search('secret')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->id);
    }

    public function test_like_wildcards_are_matched_literally(): void
    {
        $this->message($this->me, '100% done');
        $this->message($this->me, '100 things done');

        $this->search('0%')->assertJsonCount(1, 'data');
        $this->search('_')->assertJsonValidationErrors('q');
        $this->search('a_b')->assertJsonCount(0, 'data');
    }

    public function test_validation_and_access(): void
    {
        $this->search('a')->assertJsonValidationErrors('q');
        $this->search(str_repeat('a', 101))->assertJsonValidationErrors('q');

        $this->actingAs(User::factory()->create())
            ->getJson("/conversations/{$this->conversation->id}/messages/search?q=hello")
            ->assertNotFound();
    }

    private function message(User $sender, ?string $text, array $attributes = []): Message
    {
        return Message::factory()->inConversation($this->conversation, $sender)->create(['message' => $text] + $attributes);
    }

    private function search(string $term)
    {
        return $this->actingAs($this->me)->getJson("/conversations/{$this->conversation->id}/messages/search?q=".urlencode($term));
    }
}

<?php

namespace Tests\Feature\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D1 — Media, links and docs of a chat.
 */
class MediaGalleryTest extends TestCase
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

    public function test_media_tab_lists_photos_and_videos_newest_first_with_counts(): void
    {
        $photo = $this->message($this->friend, ['message_type' => Message::TYPE_IMAGE, 'attachment' => 'a.jpg', 'attachment_mime' => 'image/jpeg']);
        $this->message($this->me, ['message' => 'Just text']);
        $video = $this->message($this->me, ['message_type' => Message::TYPE_VIDEO, 'attachment' => 'b.mp4', 'attachment_mime' => 'video/mp4']);
        $this->message($this->me, ['message_type' => Message::TYPE_DOCUMENT, 'attachment' => 'c.pdf', 'attachment_name' => 'Plan.pdf']);
        $this->message($this->friend, ['message' => 'See https://example.com/page and https://laravel.com']);

        $this->gallery('media')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $video->id)
            ->assertJsonPath('data.1.id', $photo->id)
            ->assertJsonPath('data.1.attachment.url', "/messages/{$photo->id}/attachment")
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('counts', ['media' => 2, 'docs' => 1, 'links' => 1]);
    }

    public function test_docs_and_links_tabs(): void
    {
        $doc = $this->message($this->me, ['message_type' => Message::TYPE_DOCUMENT, 'attachment' => 'c.pdf', 'attachment_name' => 'Plan.pdf']);
        $link = $this->message($this->friend, ['message' => 'See https://example.com/page, `https://code.test` and https://laravel.com https://example.com/page']);
        $this->message($this->friend, ['message' => 'No links at all']);

        $this->gallery('docs')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $doc->id)
            ->assertJsonPath('data.0.attachment.name', 'Plan.pdf');

        $this->gallery('links')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $link->id)
            ->assertJsonPath('data.0.links', ['https://example.com/page', 'https://laravel.com']);
    }

    public function test_hidden_deleted_view_once_and_other_chats_are_left_out(): void
    {
        $image = ['message_type' => Message::TYPE_IMAGE, 'attachment' => 'a.jpg', 'attachment_mime' => 'image/jpeg'];
        $this->message($this->friend, $image + ['deleted_for_receiver' => true]);
        $this->message($this->me, ['message_type' => Message::TYPE_IMAGE, 'deleted_for_everyone' => true]);
        $this->message($this->friend, $image + ['attachment_meta' => ['view_once' => true]]);
        $other = Conversation::factory()->between($this->me, User::factory()->create())->create();
        Message::factory()->inConversation($other, $this->me)->create($image);
        $visible = $this->message($this->me, $image + ['attachment_meta' => ['width' => 100]]);

        $this->gallery('media')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonPath('counts.media', 1);
    }

    public function test_older_pages_and_access(): void
    {
        $ids = collect(range(1, 62))->map(fn () => $this->message($this->me, ['message_type' => Message::TYPE_DOCUMENT, 'attachment' => 'c.pdf'])->id);

        $first = $this->gallery('docs')->assertJsonCount(60, 'data')->assertJsonPath('has_more', true);
        $this->assertSame($ids->last(), $first->json('data.0.id'));

        $this->gallery('docs', $first->json('data.59.id'))
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('counts', null)
            ->assertJsonPath('data.1.id', $ids->first());

        $this->gallery('everything')->assertJsonValidationErrors('kind');

        $this->actingAs(User::factory()->create())
            ->getJson("/conversations/{$this->conversation->id}/gallery?kind=media")
            ->assertNotFound();
    }

    private function message(User $sender, array $attributes): Message
    {
        return Message::factory()->inConversation($this->conversation, $sender)->create($attributes + ['message' => null]);
    }

    private function gallery(string $kind, ?int $before = null)
    {
        return $this->actingAs($this->me)->getJson("/conversations/{$this->conversation->id}/gallery?kind={$kind}".($before ? "&before={$before}" : ''));
    }
}

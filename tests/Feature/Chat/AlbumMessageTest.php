<?php

namespace Tests\Feature\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * M13 — Several photos at once, each with its own caption, shown as an album.
 */
class AlbumMessageTest extends TestCase
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

    public function test_photos_sent_together_share_an_album_and_keep_their_own_captions(): void
    {
        $album = (string) Str::uuid();

        foreach (['Day 1', null, 'Sunset', null] as $i => $caption) {
            $this->send(array_filter([
                'attachment' => UploadedFile::fake()->image("photo{$i}.jpg", 800, 600),
                'message' => $caption,
                'album_id' => strtoupper($album),
            ]))->assertCreated()->assertJsonPath('album_id', $album);
        }

        $page = $this->actingAs($this->friend)->getJson("/conversations/{$this->conversation->id}/messages")->assertOk();

        $this->assertSame(array_fill(0, 4, $album), array_column($page->json('data'), 'album_id'));
        $this->assertSame(['Day 1', null, 'Sunset', null], array_column($page->json('data'), 'body'));
    }

    public function test_album_ids_are_validated_ignored_for_text_and_voice_and_dropped_when_forwarding(): void
    {
        $this->send(['attachment' => UploadedFile::fake()->image('a.jpg'), 'album_id' => 'not-a-uuid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('album_id');

        $this->send(['message' => 'hello', 'album_id' => (string) Str::uuid()])->assertCreated()->assertJsonPath('album_id', null);

        $id = $this->send(['attachment' => UploadedFile::fake()->image('b.jpg'), 'album_id' => (string) Str::uuid()])->json('id');
        $other = Conversation::factory()->between($this->me, User::factory()->create())->create();

        $this->actingAs($this->me)->postJson("/messages/{$id}/forward", ['conversation_ids' => [$other->id]])
            ->assertCreated()
            ->assertJsonPath('data.0.album_id', null);

        $this->actingAs($this->me)->deleteJson("/messages/{$id}", ['scope' => 'everyone'])->assertJsonPath('message.album_id', null);
        $this->assertSame(0, Message::whereKey($id)->whereNotNull('attachment_meta')->count());
    }

    private function send(array $payload)
    {
        return $this->actingAs($this->me)
            ->post("/conversations/{$this->conversation->id}/messages", $payload, ['Accept' => 'application/json']);
    }
}

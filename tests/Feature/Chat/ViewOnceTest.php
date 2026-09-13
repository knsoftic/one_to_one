<?php

namespace Tests\Feature\Chat;

use App\Events\MessageUpdated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * M22 — View once photos, videos and voice messages.
 */
class ViewOnceTest extends TestCase
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

    public function test_a_view_once_photo_has_no_links_or_preview_details(): void
    {
        $response = $this->sendPhoto(['view_once' => '1', 'message' => 'secret caption'])
            ->assertCreated()
            ->assertJsonPath('view_once.opened_at', null)
            ->assertJsonPath('view_once.available', true)
            ->assertJsonPath('attachment.view_once', true)
            ->assertJsonPath('attachment.url', null)
            ->assertJsonPath('attachment.thumbnail_url', null)
            ->assertJsonPath('album_id', null);

        $message = Message::sole();
        $this->assertSame('📷 View once photo', $message->preview());

        // The normal attachment route never serves it.
        $this->actingAs($this->friend)->get("/messages/{$message->id}/attachment")->assertNotFound();
        $this->actingAs($this->friend)->get("/messages/{$message->id}/attachment?variant=thumbnail")->assertNotFound();

        $this->assertSame($response->json('id'), $message->id);
    }

    public function test_the_receiver_opens_it_once_through_a_short_lived_link(): void
    {
        Event::fake([MessageUpdated::class]);
        $id = $this->sendPhoto(['view_once' => true])->json('id');

        // The sender cannot open it.
        $this->actingAs($this->me)->postJson("/messages/{$id}/view-once")->assertForbidden();

        $opened = $this->actingAs($this->friend)->postJson("/messages/{$id}/view-once")
            ->assertOk()
            ->assertJsonPath('message.view_once.available', false);
        $this->assertNotNull($opened->json('message.view_once.opened_at'));
        Event::assertDispatched(MessageUpdated::class);

        $url = $opened->json('url');
        $this->actingAs($this->friend)->get($url)
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

        // Not a second time, not for the sender, not without the signature, not after the link expires.
        $this->actingAs($this->friend)->postJson("/messages/{$id}/view-once")->assertStatus(410);
        $this->actingAs($this->me)->get($url)->assertStatus(410);
        $this->actingAs($this->friend)->get("/messages/{$id}/view-once/file")->assertForbidden();
        $this->travel(3)->minutes();
        $this->actingAs($this->friend)->get($url)->assertForbidden();
    }

    public function test_opened_media_is_removed_after_a_few_minutes(): void
    {
        $id = $this->sendPhoto(['view_once' => true])->json('id');
        $unopened = $this->sendPhoto(['view_once' => true])->json('id');
        $this->actingAs($this->friend)->postJson("/messages/{$id}/view-once")->assertOk();

        $this->travel(2)->minutes();
        $this->artisan('chat:purge-view-once')->expectsOutput('Removed the files of 0 opened view once message(s).');

        $this->travel(4)->minutes();
        $this->artisan('chat:purge-view-once')->expectsOutput('Removed the files of 1 opened view once message(s).');

        $this->assertNull(Message::find($id)->attachment);
        $this->assertNotNull(Message::find($unopened)->attachment);
        $this->assertCount(2, Storage::disk('chat')->allFiles(), 'the unopened photo and its thumbnail stay');
    }

    public function test_view_once_videos_and_voice_but_not_documents_or_gifs_and_no_forwarding(): void
    {
        $voice = $this->send(['voice' => $this->wav(), 'duration' => 3, 'view_once' => true])
            ->assertCreated()
            ->assertJsonPath('attachment.view_once', true)
            ->json('id');
        $this->assertSame('🎤 View once voice message', Message::find($voice)->preview());

        $pdf = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($pdf, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
        $this->send(['attachment' => new UploadedFile($pdf, 'doc.pdf', null, null, true), 'view_once' => true])
            ->assertCreated()
            ->assertJsonMissingPath('view_once');

        $other = Conversation::factory()->between($this->me, User::factory()->create())->create();
        $this->actingAs($this->me)->postJson("/messages/{$voice}/forward", ['conversation_ids' => [$other->id]])->assertForbidden();
    }

    private function sendPhoto(array $extra)
    {
        return $this->send(['attachment' => UploadedFile::fake()->image('photo.jpg', 800, 600)] + $extra);
    }

    private function send(array $payload)
    {
        return $this->actingAs($this->me)->post("/conversations/{$this->conversation->id}/messages", $payload, ['Accept' => 'application/json']);
    }

    private function wav(): UploadedFile
    {
        $samples = str_repeat(pack('v', 0), 8000);
        $header = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($samples));
        $path = tempnam(sys_get_temp_dir(), 'wav');
        file_put_contents($path, $header.$samples);

        return new UploadedFile($path, 'voice-message.wav', null, null, true);
    }
}

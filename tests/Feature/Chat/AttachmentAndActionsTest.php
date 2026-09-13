<?php

namespace Tests\Feature\Chat;

use App\Events\MessageHidden;
use App\Events\MessageUpdated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class AttachmentAndActionsTest extends TestCase
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

    private function send(array $payload, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->me)
            ->post("/conversations/{$this->conversation->id}/messages", $payload, ['Accept' => 'application/json']);
    }

    /**
     * A real uploaded file: its MIME type is detected from the content
     * (UploadedFile::fake() reports a type based on the file name only).
     */
    private function upload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function pdf(string $name = 'report.pdf'): UploadedFile
    {
        return $this->upload($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
    }

    private function docx(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Hi</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();

        return $this->upload('notes.docx', file_get_contents($path));
    }

    private function wav(): UploadedFile
    {
        $samples = str_repeat(pack('v', 0), 8000);
        $header = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($samples));

        return $this->upload('voice-message.wav', $header.$samples);
    }

    /* ------------------------------------------------------------------ */
    /* Uploads */
    /* ------------------------------------------------------------------ */

    public function test_images_are_stored_privately_with_thumbnail_and_caption(): void
    {
        $response = $this->send([
            'attachment' => UploadedFile::fake()->image('holiday.jpg', 1200, 800),
            'message' => 'Look at this 🌅',
        ])->assertCreated()
            ->assertJsonPath('type', 'image')
            ->assertJsonPath('body', 'Look at this 🌅')
            ->assertJsonPath('attachment.name', 'holiday.jpg')
            ->assertJsonPath('attachment.width', 1200)
            ->assertJsonPath('attachment.height', 800);

        $message = Message::sole();
        Storage::disk('chat')->assertExists($message->attachment);
        Storage::disk('chat')->assertExists($message->attachment_meta['thumbnail']);
        $this->assertSame('image/jpeg', $message->attachment_mime);
        $this->assertStringNotContainsString('holiday', $message->attachment, 'Stored names must be random.');

        $this->assertStringStartsWith('/messages/', $response->json('attachment.url'));
        $this->assertNotNull($response->json('attachment.thumbnail_url'));

        // Not reachable through the public storage link.
        $this->assertFileDoesNotExist(public_path('storage/'.$message->attachment));
    }

    public function test_pdf_and_docx_documents_can_be_sent(): void
    {
        $this->send(['attachment' => $this->pdf()])->assertCreated()
            ->assertJsonPath('type', 'document')
            ->assertJsonPath('attachment.name', 'report.pdf');

        $this->send(['attachment' => $this->docx()])->assertCreated()
            ->assertJsonPath('type', 'document')
            ->assertJsonPath('attachment.name', 'notes.docx');

        $this->assertSame(2, Message::where('message_type', 'document')->count());
    }

    public function test_voice_messages_store_duration(): void
    {
        $this->send(['voice' => $this->wav(), 'duration' => '12.34'])
            ->assertCreated()
            ->assertJsonPath('type', 'voice')
            ->assertJsonPath('attachment.duration', 12.3)
            ->assertJsonPath('body', null);
    }

    public function test_disallowed_or_disguised_files_are_rejected(): void
    {
        $this->send(['attachment' => $this->upload('shell.jpg', '<?php system($_GET["c"]); ?>')])
            ->assertUnprocessable()->assertJsonValidationErrors('attachment');

        $this->send(['attachment' => $this->upload('virus.exe', "MZ\x90\x00\x03".str_repeat("\0", 64))])
            ->assertUnprocessable()->assertJsonValidationErrors('attachment');

        $this->send(['attachment' => $this->upload('page.pdf', '<html><body><script>alert(1)</script></body></html>')])
            ->assertUnprocessable()->assertJsonValidationErrors('attachment');

        $this->send(['attachment' => $this->upload('fake.docx', 'plain text pretending to be a word file')])
            ->assertUnprocessable()->assertJsonValidationErrors('attachment');

        $this->send(['attachment' => UploadedFile::fake()->image('bitmap.bmp')])
            ->assertUnprocessable()->assertJsonValidationErrors('attachment');

        $this->send(['voice' => $this->upload('voice.webm', 'not audio at all')])
            ->assertUnprocessable()->assertJsonValidationErrors('voice');

        $this->assertSame(0, Message::count());
        $this->assertSame([], Storage::disk('chat')->allFiles());
    }

    public function test_file_size_limits_are_enforced(): void
    {
        config(['chat.uploads.image.max_kb' => 50]);

        $this->send(['attachment' => UploadedFile::fake()->image('big.png', 800, 800)->size(200)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attachment');
    }

    public function test_attachment_and_voice_cannot_be_combined(): void
    {
        $this->send(['attachment' => $this->pdf(), 'voice' => $this->wav()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attachment');
    }

    /* ------------------------------------------------------------------ */
    /* Private attachment access */
    /* ------------------------------------------------------------------ */

    public function test_only_participants_can_download_attachments(): void
    {
        $this->send(['attachment' => $this->pdf()])->assertCreated();
        $message = Message::sole();

        $this->actingAs($this->friend)->get("/messages/{$message->id}/attachment")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($this->friend)->get("/messages/{$message->id}/attachment?download=1")
            ->assertOk()
            ->assertDownload('report.pdf');

        $this->actingAs(User::factory()->create())->get("/messages/{$message->id}/attachment")->assertNotFound();
        $this->actingAs(User::factory()->admin()->create())->get("/messages/{$message->id}/attachment")->assertNotFound();

        auth()->logout();
        $this->get("/messages/{$message->id}/attachment")->assertRedirect(route('login'));
    }

    public function test_image_thumbnails_are_served_as_webp(): void
    {
        $this->send(['attachment' => UploadedFile::fake()->image('pic.png', 900, 900)])->assertCreated();
        $message = Message::sole();

        $this->actingAs($this->friend)->get("/messages/{$message->id}/attachment?variant=thumbnail")
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp');
    }

    /* ------------------------------------------------------------------ */
    /* Edit */
    /* ------------------------------------------------------------------ */

    public function test_sender_can_edit_a_text_message(): void
    {
        Event::fake([MessageUpdated::class]);
        $message = Message::factory()->inConversation($this->conversation, $this->me)->create(['message' => 'Helo']);

        $this->actingAs($this->me)->patchJson("/messages/{$message->id}", ['message' => 'Hello'])
            ->assertOk()
            ->assertJsonPath('body', 'Hello')
            ->assertJsonPath('is_edited', true);

        $this->assertTrue($message->fresh()->is_edited);
        $this->assertNotNull($message->fresh()->edited_at);
        Event::assertDispatched(MessageUpdated::class);
    }

    public function test_messages_cannot_be_edited_by_others_or_when_not_text(): void
    {
        $theirs = Message::factory()->inConversation($this->conversation, $this->friend)->create();
        $this->actingAs($this->me)->patchJson("/messages/{$theirs->id}", ['message' => 'hijack'])->assertForbidden();

        $this->send(['attachment' => $this->pdf()])->assertCreated();
        $document = Message::where('message_type', 'document')->sole();
        $this->actingAs($this->me)->patchJson("/messages/{$document->id}", ['message' => 'x'])->assertForbidden();

        $outsider = User::factory()->create();
        $this->actingAs($outsider)->patchJson("/messages/{$theirs->id}", ['message' => 'x'])->assertNotFound();
    }

    public function test_edit_window_is_enforced_when_configured(): void
    {
        config(['chat.edit_window_minutes' => 15]);
        $old = Message::factory()->inConversation($this->conversation, $this->me)->create(['created_at' => now()->subHour()]);

        $this->actingAs($this->me)->patchJson("/messages/{$old->id}", ['message' => 'late edit'])
            ->assertForbidden()
            ->assertJsonPath('message', 'This message can no longer be edited.');
    }

    /* ------------------------------------------------------------------ */
    /* Delete */
    /* ------------------------------------------------------------------ */

    public function test_delete_for_me_hides_the_message_only_for_me(): void
    {
        Event::fake([MessageHidden::class]);
        $message = Message::factory()->inConversation($this->conversation, $this->friend)->create();

        $this->actingAs($this->me)->deleteJson("/messages/{$message->id}", ['scope' => 'me'])
            ->assertOk()
            ->assertJsonPath('scope', 'me');

        $this->assertTrue($message->fresh()->deleted_for_receiver);
        $this->actingAs($this->me)->getJson("/conversations/{$this->conversation->id}/messages")->assertJsonCount(0, 'data');
        $this->actingAs($this->friend)->getJson("/conversations/{$this->conversation->id}/messages")->assertJsonCount(1, 'data');

        Event::assertDispatched(MessageHidden::class, fn (MessageHidden $e) => $e->userId === $this->me->id);
    }

    public function test_delete_for_everyone_wipes_content_and_files(): void
    {
        Event::fake([MessageUpdated::class]);
        $this->send(['attachment' => UploadedFile::fake()->image('secret.jpg', 400, 400), 'message' => 'private caption'])->assertCreated();
        $message = Message::sole();
        $files = [$message->attachment, $message->attachment_meta['thumbnail']];

        $this->actingAs($this->me)->deleteJson("/messages/{$message->id}", ['scope' => 'everyone'])
            ->assertOk()
            ->assertJsonPath('message.is_deleted', true)
            ->assertJsonPath('message.body', null)
            ->assertJsonMissingPath('message.attachment');

        $fresh = $message->fresh();
        $this->assertTrue($fresh->deleted_for_everyone);
        $this->assertNull($fresh->message);
        $this->assertNull($fresh->attachment);
        foreach ($files as $file) {
            Storage::disk('chat')->assertMissing($file);
        }

        $this->actingAs($this->friend)->getJson("/conversations/{$this->conversation->id}/messages")
            ->assertJsonPath('data.0.is_deleted', true)
            ->assertJsonPath('data.0.body', null);

        $this->actingAs($this->friend)->get("/messages/{$message->id}/attachment")->assertNotFound();
        Event::assertDispatched(MessageUpdated::class);
    }

    public function test_only_the_sender_can_delete_for_everyone(): void
    {
        $theirs = Message::factory()->inConversation($this->conversation, $this->friend)->create();

        $this->actingAs($this->me)->deleteJson("/messages/{$theirs->id}", ['scope' => 'everyone'])->assertForbidden();
        $this->assertFalse($theirs->fresh()->deleted_for_everyone);

        $this->actingAs(User::factory()->create())->deleteJson("/messages/{$theirs->id}", ['scope' => 'me'])->assertNotFound();
        $this->actingAs($this->me)->deleteJson("/messages/{$theirs->id}", ['scope' => 'nope'])->assertUnprocessable();
    }

    public function test_delete_for_everyone_window_is_enforced_when_configured(): void
    {
        config(['chat.delete_for_everyone_window_minutes' => 60]);
        $old = Message::factory()->inConversation($this->conversation, $this->me)->create(['created_at' => now()->subDays(2)]);

        $this->actingAs($this->me)->deleteJson("/messages/{$old->id}", ['scope' => 'everyone'])->assertForbidden();
        $this->actingAs($this->me)->deleteJson("/messages/{$old->id}", ['scope' => 'me'])->assertOk();
    }

    public function test_files_are_removed_when_both_participants_delete_for_themselves(): void
    {
        $this->send(['attachment' => $this->pdf()])->assertCreated();
        $message = Message::sole();
        $path = $message->attachment;

        $this->actingAs($this->me)->deleteJson("/messages/{$message->id}", ['scope' => 'me'])->assertOk();
        Storage::disk('chat')->assertExists($path);

        $this->actingAs($this->friend)->deleteJson("/messages/{$message->id}", ['scope' => 'me'])->assertOk();
        Storage::disk('chat')->assertMissing($path);
    }

    public function test_replies_show_a_masked_preview_when_the_original_was_deleted(): void
    {
        $original = Message::factory()->inConversation($this->conversation, $this->friend)->create(['message' => 'original text']);
        $this->send(['message' => 'my reply', 'reply_to_id' => $original->id])->assertCreated();

        $this->actingAs($this->friend)->deleteJson("/messages/{$original->id}", ['scope' => 'everyone'])->assertOk();

        $this->actingAs($this->me)->getJson("/conversations/{$this->conversation->id}/messages")
            ->assertJsonPath('data.1.reply_to.is_deleted', true)
            ->assertJsonPath('data.1.reply_to.preview', 'This message was deleted');
    }
}

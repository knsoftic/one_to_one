<?php

namespace Tests\Feature\Chat;

use App\Models\ChatSetting;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * D7 — Export chat.
 */
class ChatExportTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $friend;

    private Conversation $chat;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('chat');
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow('2026-09-15 10:00:00');

        $this->me = User::factory()->create(['name' => 'Bilal Ahmed']);
        $this->friend = User::factory()->create(['name' => 'Ayesha Khan']);
        $this->chat = Conversation::factory()->between($this->me, $this->friend)->create();
        Contact::create(['user_id' => $this->me->id, 'contact_user_id' => $this->friend->id, 'name' => 'Ayesha Baji', 'phone' => '03001234567']);
    }

    protected function tearDown(): void
    {
        // Downloads are never sent in tests, so their files stay behind.
        foreach (glob(storage_path('app/exports/*')) ?: [] as $file) {
            @unlink($file);
        }
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_text_export_has_the_messages_i_can_see(): void
    {
        $this->message($this->friend, ['message' => "Assalam o alaikum!\nKaise ho?", 'created_at' => '2026-09-14 09:05:00']);
        $this->message($this->me, ['message' => 'Theek, aap?', 'created_at' => '2026-09-14 09:06:00']);
        $this->message($this->friend, ['message' => 'hidden from me', 'deleted_for_receiver' => true]);
        $this->message($this->friend, ['message' => null, 'deleted_for_everyone' => true, 'created_at' => '2026-09-14 09:07:00']);
        $this->message($this->friend, ['message' => 'Tickets', 'message_type' => Message::TYPE_DOCUMENT, 'attachment' => 'attachments/t.pdf', 'attachment_name' => 'Tickets.pdf', 'created_at' => '2026-09-14 09:08:00']);
        $this->message($this->me, ['message_type' => Message::TYPE_LOCATION, 'attachment_meta' => ['lat' => 31.5204, 'lng' => 74.3587], 'created_at' => '2026-09-14 09:09:00']);
        $this->message($this->friend, ['message_type' => Message::TYPE_POLL, 'attachment_meta' => ['question' => 'Dinner?', 'options' => [['id' => 1, 'text' => 'Karahi'], ['id' => 2, 'text' => 'BBQ']]], 'created_at' => '2026-09-14 09:10:00']);
        $this->message($this->friend, ['message_type' => Message::TYPE_IMAGE, 'attachment' => 'attachments/v.jpg', 'attachment_meta' => ['view_once' => true], 'created_at' => '2026-09-14 09:11:00']);

        $response = $this->actingAs($this->me)->get("/conversations/{$this->chat->id}/export")->assertOk();
        $this->assertStringContainsString('attachment; filename="One2One Chat with Ayesha Baji.txt"', $response->headers->get('Content-Disposition'));

        $text = $response->streamedContent() ?: file_get_contents($response->getFile()->getPathname());
        $text = ltrim($text, "\u{FEFF}");

        $this->assertStringStartsWith("Ayesha Baji\nExported from One2One Chat on 15/09/2026, 10:00.", $text);
        $this->assertStringContainsString("14/09/2026, 09:05 - Ayesha Baji: Assalam o alaikum!\nKaise ho?\n", $text);
        $this->assertStringContainsString('14/09/2026, 09:06 - Bilal Ahmed: Theek, aap?', $text);
        $this->assertStringContainsString('14/09/2026, 09:07 - Ayesha Baji: This message was deleted', $text);
        $this->assertStringContainsString('14/09/2026, 09:08 - Ayesha Baji: <Document omitted (Tickets.pdf)> Tickets', $text);
        $this->assertStringContainsString('14/09/2026, 09:09 - Bilal Ahmed: Location: https://maps.google.com/?q=31.5204,74.3587', $text);
        $this->assertStringContainsString("POLL: Dinner?\nOPTION: Karahi\nOPTION: BBQ", $text);
        $this->assertStringContainsString('<View once media omitted>', $text);
        $this->assertStringNotContainsString('hidden from me', $text);
    }

    public function test_zip_export_includes_media(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('PHP zip extension is not installed.');
        }

        Storage::disk('chat')->put('attachments/photo.jpg', 'jpeg-bytes');
        $photo = $this->message($this->friend, ['message' => 'Look', 'message_type' => Message::TYPE_IMAGE, 'attachment' => 'attachments/photo.jpg', 'attachment_mime' => 'image/jpeg', 'created_at' => '2026-09-14 12:00:00']);
        $this->message($this->friend, ['message_type' => Message::TYPE_VOICE, 'attachment' => 'attachments/missing.webm', 'created_at' => '2026-09-14 12:01:00']);

        $response = $this->actingAs($this->me)->get("/conversations/{$this->chat->id}/export?media=1")->assertOk();
        $this->assertStringContainsString('One2One Chat with Ayesha Baji.zip', $response->headers->get('Content-Disposition'));

        $copy = tempnam(sys_get_temp_dir(), 'zip');
        copy($response->getFile()->getPathname(), $copy);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($copy));

        $name = str_pad((string) $photo->id, 8, '0', STR_PAD_LEFT).'-PHOTO-2026-09-14.jpg';
        $this->assertSame('jpeg-bytes', $zip->getFromName("media/{$name}"));
        $text = $zip->getFromName('chat.txt');
        $this->assertStringContainsString("12:00 - Ayesha Baji: <attached: media/{$name}> Look", $text);
        $this->assertStringContainsString('12:01 - Ayesha Baji: <Voice message omitted>', $text);
        $zip->close();
        @unlink($copy);

        // Temporary text files are cleaned up.
        $this->assertSame([], glob(storage_path('app/exports/*.txt')));
    }

    public function test_access_and_locked_chats(): void
    {
        $this->actingAs(User::factory()->create())->get("/conversations/{$this->chat->id}/export")->assertNotFound();

        $this->me->forceFill(['chat_lock_pin' => bcrypt('1234')])->save();
        ChatSetting::query()->create(['user_id' => $this->me->id, 'conversation_id' => $this->chat->id, 'locked_at' => now()]);
        $this->actingAs($this->me)->get("/conversations/{$this->chat->id}/export")->assertStatus(423);
    }

    private function message(User $sender, array $attributes): Message
    {
        return Message::factory()->inConversation($this->chat, $sender)->create($attributes + ['message' => null]);
    }
}

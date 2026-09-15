<?php

namespace Tests\Feature\Account;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * D5 — media auto-download, D6 — manage storage.
 */
class StorageAndDataTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $friend;

    private Conversation $chat;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('chat');

        $this->me = User::factory()->create();
        $this->friend = User::factory()->create(['name' => 'Ayesha Khan']);
        $this->chat = Conversation::factory()->between($this->me, $this->friend)->create();
    }

    public function test_auto_download_choices_per_network(): void
    {
        $this->actingAs($this->me)->patchJson('/settings/preferences', ['auto_download' => ['mobile' => []]])
            ->assertOk()
            ->assertJsonPath('preferences.auto_download', ['wifi' => ['photos', 'gifs', 'videos'], 'mobile' => []]);

        $this->patchJson('/settings/preferences', ['auto_download' => ['wifi' => ['photos']]])
            ->assertOk()
            ->assertJsonPath('preferences.auto_download', ['wifi' => ['photos'], 'mobile' => []]);

        $this->patchJson('/settings/preferences', ['auto_download' => ['wifi' => ['documents']]])->assertJsonValidationErrors('auto_download.wifi.0');
        $this->patchJson('/settings/preferences', ['auto_download' => ['satellite' => ['photos']]])->assertJsonValidationErrors('auto_download');

        $this->get('/settings?tab=storage')->assertOk()->assertSee('Media auto-download')->assertSee('Manage storage');
        $this->get('/chat')->assertOk()->assertSee('"auto_download":{"wifi":["photos"],"mobile":[]}', false);
    }

    public function test_summary_counts_space_by_kind_and_chat(): void
    {
        $this->file($this->friend, Message::TYPE_IMAGE, 2_000_000, ['attachment_mime' => 'image/jpeg']);
        $this->file($this->me, Message::TYPE_VIDEO, 7_000_000);
        $this->file($this->friend, Message::TYPE_DOCUMENT, 300_000, ['attachment_name' => 'Plan.pdf']);
        $this->file($this->friend, Message::TYPE_IMAGE, 50_000, ['attachment_mime' => 'image/gif']);
        // Not counted: deleted for me, deleted for everyone, view once, someone else's chat.
        $this->file($this->friend, Message::TYPE_IMAGE, 9_000_000, ['deleted_for_receiver' => true]);
        $this->file($this->friend, Message::TYPE_IMAGE, 9_000_000, ['deleted_for_everyone' => true]);
        $this->file($this->friend, Message::TYPE_IMAGE, 9_000_000, ['attachment_meta' => ['view_once' => true]]);
        $other = Conversation::factory()->between($this->friend, User::factory()->create())->create();
        Message::factory()->inConversation($other, $this->friend)->create(['message_type' => Message::TYPE_VIDEO, 'attachment' => 'x.mp4', 'attachment_size' => 9_000_000]);

        $group = Conversation::factory()->create(['type' => Conversation::TYPE_GROUP, 'name' => 'Family']);
        $group->members()->createMany([
            ['user_id' => $this->me->id, 'role' => 'member', 'joined_at' => now()->subDay()],
            ['user_id' => $this->friend->id, 'role' => 'admin', 'joined_at' => now()->subDay()],
        ]);
        Message::factory()->inConversation($group, $this->friend)->create(['receiver_id' => null, 'message_type' => Message::TYPE_VOICE, 'attachment' => 'v.webm', 'attachment_size' => 120_000]);

        Contact::create(['user_id' => $this->me->id, 'contact_user_id' => $this->friend->id, 'name' => 'Ayesha (office)', 'phone' => '03001234567']);

        $this->actingAs($this->me)->getJson('/settings/storage')
            ->assertOk()
            ->assertJsonPath('total', ['bytes' => 9_470_000, 'files' => 5])
            ->assertJsonPath('kinds.photos', ['bytes' => 2_000_000, 'files' => 1])
            ->assertJsonPath('kinds.videos', ['bytes' => 7_000_000, 'files' => 1])
            ->assertJsonPath('kinds.documents', ['bytes' => 300_000, 'files' => 1])
            ->assertJsonPath('kinds.gifs', ['bytes' => 50_000, 'files' => 1])
            ->assertJsonPath('kinds.voice', ['bytes' => 120_000, 'files' => 1])
            ->assertJsonPath('large', ['bytes' => 7_000_000, 'files' => 1])
            ->assertJsonPath('chats.0.id', $this->chat->id)
            ->assertJsonPath('chats.0.name', 'Ayesha (office)')
            ->assertJsonPath('chats.0.bytes', 9_350_000)
            ->assertJsonPath('chats.1.name', 'Family')
            ->assertJsonPath('chats.1.files', 1);
    }

    public function test_files_of_a_chat_large_files_and_access(): void
    {
        $small = $this->file($this->friend, Message::TYPE_DOCUMENT, 300_000, ['attachment_name' => 'Plan.pdf']);
        $big = $this->file($this->me, Message::TYPE_VIDEO, 7_000_000, ['attachment_meta' => ['thumbnail' => 'v_thumb.webp', 'duration' => 30]]);

        $this->actingAs($this->me)->getJson("/settings/storage/files?conversation={$this->chat->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $big->id)
            ->assertJsonPath('data.0.kind', 'videos')
            ->assertJsonPath('data.0.is_mine', true)
            ->assertJsonPath('data.0.thumbnail_url', "/messages/{$big->id}/attachment?variant=thumbnail")
            ->assertJsonPath('data.1.name', 'Plan.pdf')
            ->assertJsonPath('data.1.thumbnail_url', null);

        $this->getJson("/settings/storage/files?conversation={$this->chat->id}&sort=newest")->assertJsonPath('data.0.id', $big->id);
        $this->getJson('/settings/storage/files?large=1')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.chat.name', 'Ayesha Khan');

        $stranger = Conversation::factory()->between($this->friend, User::factory()->create())->create();
        $this->getJson("/settings/storage/files?conversation={$stranger->id}")->assertNotFound();
        $this->getJson('/settings/storage/files?sort=biggest')->assertJsonValidationErrors('sort');
        $this->assertNotNull($small);
    }

    public function test_delete_for_me_frees_files_nobody_sees(): void
    {
        Storage::disk('chat')->put('attachments/mine.mp4', 'video');
        $mine = $this->file($this->me, Message::TYPE_VIDEO, 7_000_000, ['attachment' => 'attachments/mine.mp4', 'deleted_for_receiver' => true]);
        $theirs = $this->file($this->friend, Message::TYPE_IMAGE, 2_000_000);
        $notMine = Message::factory()->inConversation(Conversation::factory()->between($this->friend, User::factory()->create())->create(), $this->friend)
            ->create(['message_type' => Message::TYPE_IMAGE, 'attachment' => 'y.jpg', 'attachment_size' => 10]);

        $this->actingAs($this->me)->postJson('/settings/storage/delete', ['message_ids' => [$mine->id, $theirs->id, $notMine->id]])
            ->assertOk()
            ->assertJsonPath('deleted', 2)
            ->assertJsonPath('bytes', 9_000_000)
            ->assertJsonPath('message', '2 files deleted for you.');

        $this->assertTrue($theirs->fresh()->deleted_for_receiver);
        $this->assertFalse($theirs->fresh()->deleted_for_sender);
        // The other person had already deleted my video: its file is gone.
        $this->assertNull($mine->fresh()->attachment);
        Storage::disk('chat')->assertMissing('attachments/mine.mp4');
        $this->assertFalse($notMine->fresh()->deleted_for_receiver);

        $this->getJson('/settings/storage')->assertJsonPath('total.files', 0);
        $this->postJson('/settings/storage/delete', ['message_ids' => []])->assertJsonValidationErrors('message_ids');
    }

    private function file(User $sender, string $type, int $size, array $attributes = []): Message
    {
        return Message::factory()->inConversation($this->chat, $sender)->create($attributes + [
            'message' => null,
            'message_type' => $type,
            'attachment' => 'attachments/'.uniqid().'.bin',
            'attachment_size' => $size,
        ]);
    }
}

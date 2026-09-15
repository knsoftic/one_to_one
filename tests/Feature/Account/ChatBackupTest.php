<?php

namespace Tests\Feature\Account;

use App\Models\AdminAuditLog;
use App\Models\ChatBackup;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * D8 — chat backups: a person's own ZIP and full server backups from the admin panel.
 */
class ChatBackupTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $friend;

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('PHP zip extension is not installed.');
        }

        Storage::fake('local');
        Storage::fake('chat');
        Storage::fake('public');

        $this->me = User::factory()->create(['name' => 'Bilal Ahmed']);
        $this->friend = User::factory()->create(['name' => 'Ayesha Khan']);
    }

    public function test_personal_backup_has_every_chat_i_can_see(): void
    {
        $chat = Conversation::factory()->between($this->me, $this->friend)->create();
        Message::factory()->inConversation($chat, $this->friend)->create(['message' => 'Kal milte hain']);
        Storage::disk('chat')->put('attachments/p.jpg', 'photo');
        Message::factory()->inConversation($chat, $this->me)->create(['message' => null, 'message_type' => Message::TYPE_IMAGE, 'attachment' => 'attachments/p.jpg', 'attachment_mime' => 'image/jpeg']);
        $other = Conversation::factory()->between($this->friend, User::factory()->create(['name' => 'Sara']))->create();
        Message::factory()->inConversation($other, $this->friend)->create(['message' => 'not for Bilal']);

        $this->actingAs($this->me)->getJson('/settings/backups')->assertOk()->assertJsonPath('backup', null)->assertJsonPath('available', true);

        // The queue runs right away in tests.
        $response = $this->postJson('/settings/backups', ['media' => true])
            ->assertStatus(202)
            ->assertJsonPath('backup.status', 'ready')
            ->assertJsonPath('backup.include_media', true)
            ->assertJsonPath('backup.stats.chats', 1)
            ->assertJsonPath('backup.stats.media', 1);

        $backup = ChatBackup::sole();
        $this->assertTrue($backup->expires_at->between(now()->addDays(6), now()->addDays(8)));
        Storage::disk('local')->assertExists($backup->path);

        $url = $response->json('backup.download_url');
        $this->assertSame("/settings/backups/{$backup->id}/download", $url);
        $this->get($url)->assertOk()->assertDownload();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($backup->path)));
        $this->assertStringContainsString('sign in with the same', $zip->getFromName('README.txt'));
        $text = $zip->getFromName('chats/Ayesha Khan/chat.txt');
        $this->assertStringContainsString('Ayesha Khan: Kal milte hain', $text);
        $this->assertStringContainsString('<attached: media/', $text);
        $this->assertFalse($zip->locateName('chats/Sara/chat.txt'));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $this->assertCount(1, array_filter($names, fn ($name) => str_starts_with($name, 'chats/Ayesha Khan/media/')));
        $zip->close();

        // Nobody else can download it.
        $this->actingAs($this->friend)->get($url)->assertNotFound();
        $this->actingAs($this->friend)->deleteJson("/settings/backups/{$backup->id}")->assertNotFound();
    }

    public function test_a_new_backup_replaces_the_old_one_and_waiting_ones_are_not_doubled(): void
    {
        $this->actingAs($this->me)->postJson('/settings/backups')->assertStatus(202)->assertJsonPath('backup.include_media', false);
        $first = ChatBackup::sole();

        $this->postJson('/settings/backups')->assertStatus(202);
        $this->assertSame(1, ChatBackup::query()->count());
        Storage::disk('local')->assertMissing($first->path);

        Queue::fake();
        $this->postJson('/settings/backups')->assertJsonPath('backup.status', 'pending');
        $this->postJson('/settings/backups')->assertJsonPath('backup.status', 'pending');
        $this->assertSame(1, ChatBackup::query()->where('status', 'pending')->count());

        // Without a queue worker the scheduler makes it.
        ChatBackup::query()->where('status', 'pending')->update(['created_at' => now()->subMinutes(2)]);
        $this->artisan('chat:backups')->assertSuccessful();
        $this->getJson('/settings/backups')->assertJsonPath('backup.status', 'ready');
    }

    public function test_expired_backups_are_removed(): void
    {
        $this->actingAs($this->me)->postJson('/settings/backups')->assertStatus(202);
        $backup = ChatBackup::sole();

        $this->travel(8)->days();
        $this->getJson('/settings/backups')->assertJsonPath('backup.status', 'expired')->assertJsonPath('backup.download_url', null);
        $this->get("/settings/backups/{$backup->id}/download")->assertNotFound();

        $this->artisan('chat:backups')->assertSuccessful();
        Storage::disk('local')->assertMissing($backup->path);
        $this->assertNull($backup->fresh()->path);
    }

    public function test_admins_make_download_and_delete_server_backups(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        Storage::disk('chat')->put('attachments/a.jpg', 'photo');
        Storage::disk('public')->put('avatars/me.webp', 'avatar');

        $this->actingAs($this->me)->get('/admin/backups')->assertForbidden();

        $this->actingAs($admin)->get('/admin/backups')->assertOk()->assertSee('No server backups yet')->assertSee('same');
        $this->post('/admin/backups', ['files' => '1'])->assertRedirect('/admin/backups');

        $backup = ChatBackup::query()->where('kind', 'server')->sole();
        $this->assertSame('ready', $backup->status);
        $this->assertGreaterThan(10, $backup->stats['tables']);
        $this->assertSame(2, $backup->stats['files']);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($backup->path)));
        $sql = $zip->getFromName('database.sql');
        $this->assertStringContainsString('CREATE TABLE `users`', $sql);
        $this->assertStringContainsString("'Bilal Ahmed'", $sql);
        $this->assertSame('photo', $zip->getFromName('files/chat/attachments/a.jpg'));
        $this->assertSame('avatar', $zip->getFromName('files/public/avatars/me.webp'));
        $this->assertStringContainsString('APP_KEY', $zip->getFromName('RESTORE.txt'));
        $zip->close();

        $this->get('/admin/backups')->assertSee('Download');
        $this->get("/admin/backups/{$backup->id}/download")->assertOk()->assertDownload();
        $this->assertSame(['backup.created', 'backup.downloaded'], AdminAuditLog::query()->orderBy('id')->pluck('action')->all());

        // A personal backup can't be downloaded from the admin panel.
        $this->actingAs($this->me)->postJson('/settings/backups')->assertStatus(202);
        $personal = ChatBackup::query()->where('kind', 'personal')->sole();
        $this->actingAs($admin)->get("/admin/backups/{$personal->id}/download")->assertNotFound();

        $this->delete("/admin/backups/{$backup->id}")->assertRedirect('/admin/backups');
        Storage::disk('local')->assertMissing($backup->path);
        $this->assertSame('backup.deleted', AdminAuditLog::query()->latest('id')->value('action'));
    }

    public function test_server_backups_keep_the_newest_few(): void
    {
        config(['chat.backups.server_keep' => 2]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $backups = app(BackupService::class);

        foreach (range(1, 3) as $i) {
            $backups->requestServer($admin, false);
        }

        $this->assertSame(2, ChatBackup::query()->where('kind', 'server')->count());
    }
}

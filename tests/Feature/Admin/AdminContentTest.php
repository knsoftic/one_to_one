<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use App\Models\Community;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Admin panel — reading chats (recorded in the audit log), message search, deleting
 * messages, groups, channels, communities and status updates.
 */
class AdminContentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $ayesha;

    private User $bilal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Site Admin']);
        $this->ayesha = User::factory()->create(['name' => 'Ayesha Khan']);
        $this->bilal = User::factory()->create(['name' => 'Bilal Ahmed']);
    }

    public function test_admins_read_any_chat_and_every_opening_is_recorded(): void
    {
        Storage::fake('chat');
        $chat = Conversation::factory()->between($this->ayesha, $this->bilal)->create();
        $this->actingAs($this->ayesha)->postJson("/conversations/{$chat->id}/messages", ['message' => 'Meet at 5 near the gate'])->assertCreated();
        $photo = $this->actingAs($this->bilal)->post("/conversations/{$chat->id}/messages", ['attachment' => UploadedFile::fake()->image('receipt.jpg', 300, 300)], ['Accept' => 'application/json'])->json('id');

        // Regular people can't open the admin views.
        $this->actingAs($this->bilal)->get('/admin/chats')->assertForbidden();
        $this->actingAs($this->bilal)->get("/admin/chats/{$chat->id}")->assertForbidden();

        $this->actingAs($this->admin)->get('/admin/chats?type=direct&q=Ayesha')->assertOk()->assertSee('Ayesha Khan &amp; Bilal Ahmed', false);
        $this->actingAs($this->admin)->get("/admin/chats?user={$this->bilal->id}")->assertOk()->assertSee("Bilal Ahmed's chats");

        $this->actingAs($this->admin)->get("/admin/chats/{$chat->id}")
            ->assertOk()
            ->assertSee('Meet at 5 near the gate')
            ->assertSee(route('admin.messages.attachment', $photo))
            ->assertSee('recorded in the audit log');

        $log = AdminAuditLog::query()->where('action', 'chat.viewed')->sole();
        $this->assertSame($this->admin->id, $log->admin_id);
        $this->assertSame(['Conversation', $chat->id], [$log->target_type, $log->target_id]);
        $this->assertSame('Opened the chat "Ayesha Khan & Bilal Ahmed"', $log->description);

        // Older pages don't add another entry; the photo is served to the admin.
        $this->actingAs($this->admin)->get("/admin/chats/{$chat->id}?before={$photo}")->assertOk()->assertDontSee(route('admin.messages.attachment', $photo));
        $this->assertSame(1, AdminAuditLog::query()->where('action', 'chat.viewed')->count());
        $this->actingAs($this->admin)->get("/admin/messages/{$photo}/attachment")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs($this->bilal)->get("/admin/messages/{$photo}/attachment")->assertForbidden();

        // Search all messages (recorded), then delete one for everyone.
        $this->actingAs($this->admin)->get('/admin/messages?q=gate')->assertOk()->assertSee('near the <mark>gate</mark>', false)->assertSee('Ayesha Khan');
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'messages.searched', 'description' => 'Searched all messages for "gate"']);
        $this->actingAs($this->admin)->get('/admin/messages?q=a')->assertOk()->assertSee('Type at least 2 letters');

        $text = Message::query()->where('message', 'Meet at 5 near the gate')->sole();
        $this->actingAs($this->admin)->from("/admin/chats/{$chat->id}")->delete("/admin/messages/{$text->id}")
            ->assertRedirect("/admin/chats/{$chat->id}")->assertSessionHas('status');
        $this->assertTrue($text->fresh()->deleted_for_everyone);
        $this->assertNull($text->fresh()->message);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'message.deleted', 'target_id' => $text->id]);
        $this->actingAs($this->admin)->delete("/admin/messages/{$text->id}")->assertNotFound();

        // The person's own privacy settings explain this.
        $this->actingAs($this->ayesha)->get('/settings?tab=privacy')->assertSee('administrators can open chats');
        $this->actingAs($this->admin)->get('/admin/audit')->assertOk()->assertSee('Opened the chat')->assertSee('Deleted a message from Ayesha Khan');
    }

    public function test_groups_channels_and_communities_can_be_seen_and_deleted(): void
    {
        $group = $this->actingAs($this->ayesha)->postJson('/groups', ['name' => 'Cricket Club', 'member_ids' => [$this->bilal->id]])->assertCreated()->json('id');
        $channel = $this->actingAs($this->ayesha)->postJson('/channels', ['name' => 'Ayesha Recipes'])->assertCreated()->json('id');
        $community = $this->actingAs($this->ayesha)->postJson('/communities', ['name' => 'Model Town', 'group_ids' => [$group]])->assertCreated()->json('id');

        $this->actingAs($this->admin)->get('/admin/groups')->assertOk()->assertSee('Cricket Club');
        $this->actingAs($this->admin)->get("/admin/groups/{$group}")->assertOk()->assertSee('Bilal Ahmed')->assertSee('Model Town');
        $this->actingAs($this->admin)->get('/admin/channels')->assertOk()->assertSee('Ayesha Recipes');
        $this->actingAs($this->admin)->get("/admin/channels/{$channel}")->assertOk()->assertSee('Ayesha Khan');
        $this->actingAs($this->admin)->get('/admin/communities')->assertOk()->assertSee('Model Town');
        $this->actingAs($this->admin)->get("/admin/communities/{$community}")->assertOk()->assertSee('Cricket Club')->assertSee('Bilal Ahmed');

        // Delete the community: its group stays as an ordinary group.
        $this->actingAs($this->admin)->delete("/admin/communities/{$community}")->assertRedirect(route('admin.communities'));
        $this->assertNull(Community::find($community));
        $this->assertNull(Conversation::find($group)->community_id);

        // Delete the group for everyone.
        $this->actingAs($this->admin)->delete("/admin/groups/{$group}")->assertRedirect(route('admin.groups'));
        $this->assertNotNull(Conversation::find($group)->ended_at);
        $this->assertSame('An administrator deleted this group', Conversation::find($group)->messages()->latest('id')->first()->systemText());
        $this->actingAs($this->bilal)->postJson("/conversations/{$group}/messages", ['message' => 'hello?'])->assertForbidden();
        $this->actingAs($this->admin)->delete("/admin/groups/{$group}")->assertNotFound();

        // Delete the channel.
        $this->actingAs($this->admin)->delete("/admin/channels/{$channel}")->assertRedirect(route('admin.channels'));
        $this->assertNull(Conversation::find($channel));

        $this->assertSame(
            ['community.deleted', 'group.deleted', 'channel.deleted'],
            AdminAuditLog::query()->orderBy('id')->pluck('action')->all(),
        );
        $this->actingAs($this->bilal)->delete("/admin/groups/{$group}")->assertForbidden();
    }

    public function test_status_updates_can_be_removed(): void
    {
        $status = $this->actingAs($this->ayesha)->postJson('/statuses', ['text' => 'Eid Mubarak everyone', 'background' => 'violet', 'font' => 1])->assertCreated()->json('id');

        $this->actingAs($this->admin)->get('/admin/statuses')->assertOk()->assertSee('Eid Mubarak everyone')->assertSee('Ayesha Khan');
        $this->actingAs($this->admin)->get("/admin/statuses?user={$this->bilal->id}")->assertOk()->assertDontSee('Eid Mubarak everyone');
        $this->actingAs($this->admin)->delete("/admin/statuses/{$status}")->assertSessionHas('status');
        $this->assertNull(Status::find($status));
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'status.deleted', 'description' => 'Deleted a status update from Ayesha Khan']);
    }
}

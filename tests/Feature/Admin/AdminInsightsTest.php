<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use App\Models\BlockedUser;
use App\Models\Call;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\DeviceToken;
use App\Models\Message;
use App\Models\User;
use App\Models\UserLogin;
use App\Models\UserReport;
use App\Services\AdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Admin panel upgrade: dashboard ranges and health, the users list at scale (filters,
 * sorting, bulk actions, CSV export), sign-in history and every tab of a user's page.
 */
class AdminInsightsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->admin = User::factory()->admin()->create(['name' => 'Site Admin']);
    }

    public function test_dashboard_ranges_charts_and_health(): void
    {
        $ayesha = User::factory()->create(['name' => 'Ayesha Khan']);
        $bilal = User::factory()->create(['name' => 'Bilal Ahmed']);
        $chat = Conversation::factory()->between($ayesha, $bilal)->create();
        Message::factory()->count(3)->inConversation($chat, $ayesha)->create();
        Message::factory()->inConversation($chat, $bilal)->create(['message_type' => Message::TYPE_IMAGE, 'attachment' => 'a.jpg', 'attachment_size' => 2048]);

        $this->actingAs($this->admin)->get('/admin')
            ->assertOk()
            ->assertSee('Last 30 days')
            ->assertSee('Most active people')
            ->assertSee('Ayesha Khan')
            ->assertSee('Server health')
            ->assertSee('What people send');

        $this->get('/admin?days=7')->assertOk()->assertSee('in 7 days');
        $this->get('/admin?days=90&refresh=1')->assertOk();
        $this->get('/admin?days=12')->assertSessionHasErrors('days');
    }

    public function test_sign_ins_wrong_passwords_and_sign_outs_are_recorded(): void
    {
        $user = User::factory()->create(['email' => 'hina@example.com', 'password' => 'secret-pass-1']);

        $this->post('/login', ['login' => 'hina@example.com', 'password' => 'wrong-pass-1'])->assertSessionHasErrors();
        $this->post('/login', ['login' => 'nobody@example.com', 'password' => 'wrong-pass-1']);
        $this->post('/login', ['login' => 'hina@example.com', 'password' => 'secret-pass-1'])->assertRedirect();
        $this->post('/logout');

        $this->assertSame(['failed', 'login', 'logout'], UserLogin::query()->orderBy('id')->pluck('event')->all());
        $this->assertSame('password', UserLogin::query()->where('event', 'login')->value('method'));
        $this->assertSame(3, UserLogin::query()->where('user_id', $user->id)->count());

        $this->actingAs($this->admin)->get("/admin/users/{$user->id}?tab=devices")
            ->assertOk()
            ->assertSee('Sign-in history')
            ->assertSee('Wrong password')
            ->assertSee('Signed out');
        $this->get("/admin/users/{$user->id}?tab=devices&event=failed")->assertOk()->assertDontSee('Signed out</span>', false);
    }

    public function test_every_tab_of_a_user_page(): void
    {
        $user = User::factory()->create(['name' => 'Hina Tariq']);
        $friend = User::factory()->create(['name' => 'Omar Farooq']);
        $chat = Conversation::factory()->between($user, $friend)->create();
        Message::factory()->count(4)->inConversation($chat, $user)->create();
        Contact::create(['user_id' => $user->id, 'contact_user_id' => $friend->id, 'name' => 'Omar Office', 'phone' => '03001234567']);
        Call::query()->create(['conversation_id' => $chat->id, 'caller_id' => $user->id, 'callee_id' => $friend->id, 'type' => 'video', 'status' => 'ended', 'end_reason' => 'completed', 'duration' => 125]);
        UserReport::query()->create(['reporter_id' => $friend->id, 'reported_user_id' => $user->id, 'reason' => 'spam']);
        DeviceToken::create(['user_id' => $user->id, 'token_hash' => str_repeat('c', 64), 'platform' => 'android', 'app_version' => '1.2']);
        $group = $this->actingAs($user)->postJson('/groups', ['name' => 'Cousins', 'member_ids' => [$friend->id]])->assertCreated()->json('id');
        BlockedUser::create(['user_id' => $friend->id, 'blocked_user_id' => $user->id]);
        // Someone else signed in from the same network.
        UserLogin::query()->create(['user_id' => $user->id, 'event' => 'login', 'method' => 'password', 'ip_address' => '39.45.1.9', 'created_at' => now()]);
        UserLogin::query()->create(['user_id' => $friend->id, 'event' => 'login', 'method' => 'password', 'ip_address' => '39.45.1.9', 'created_at' => now()]);

        $this->actingAs($this->admin);
        $page = fn (string $tab) => $this->get("/admin/users/{$user->id}".($tab === 'overview' ? '' : "?tab={$tab}"))->assertOk();

        $page('overview')->assertSee('Messages sent, last 30 days')->assertSee('Download all account data');
        $page('activity')->assertSee('Messages sent, last 90 days')->assertSee('Time of day')->assertSee('Where they write most')->assertSee('Omar Farooq');
        $page('chats')->assertSee('Omar Farooq');
        $page('groups')->assertSee('Cousins')->assertSee('created by them');
        $page('devices')->assertSee('app 1.2')->assertSee('Other accounts on the same networks')->assertSee('Omar Farooq');
        $page('contacts')->assertSee('Omar Office')->assertSee('Blocked by');
        $page('calls')->assertSee('Outgoing video')->assertSee('02:05');
        $page('reports')->assertSee('Reports about Hina Tariq');
        $page('settings')->assertSee('Storage in their chats')->assertSee('Auto-download');
        $page('history')->assertSee('Admin history');
        $this->get("/admin/users/{$user->id}?tab=nope")->assertOk()->assertSee('Messages sent, last 30 days');
        $this->assertNotNull($group);
    }

    public function test_users_list_filters_sorting_and_prefix_search_for_many_accounts(): void
    {
        $old = User::factory()->create(['name' => 'Zara Old', 'created_at' => now()->subDays(200), 'last_seen' => now()->subDays(120)]);
        $new = User::factory()->create(['name' => 'Amir New', 'last_seen' => now()]);
        DeviceToken::create(['user_id' => $new->id, 'token_hash' => str_repeat('d', 64), 'platform' => 'android']);

        $this->actingAs($this->admin);
        $this->get('/admin/users?joined=30')->assertSee('Amir New')->assertDontSee('Zara Old');
        $this->get('/admin/users?inactive=90')->assertSee('Zara Old')->assertDontSee('Amir New');
        $this->get('/admin/users?app=1')->assertSee('Amir New')->assertDontSee('Zara Old');
        $this->get('/admin/users?sort=name&per_page=50')->assertOk()->assertSeeInOrder(['Amir New', 'Site Admin', 'Zara Old']);
        $this->get('/admin/users?sort=bogus')->assertSessionHasErrors('sort');

        // With many accounts, search matches the start of names unless "contains" is ticked.
        Cache::put('admin:users:large', true, 60);
        $this->get('/admin/users?q=New')->assertDontSee('Amir New')->assertSee('start with');
        $this->get('/admin/users?q=Amir')->assertSee('Amir New');
        $this->get('/admin/users?q=New&contains=1')->assertSee('Amir New');
        $this->get("/admin/users?q={$old->id}")->assertSee('Zara Old');
        $this->assertTrue(app(AdminService::class)->largeUserTable());
    }

    public function test_bulk_actions_skip_admins_and_are_audited(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        $otherAdmin = User::factory()->admin()->create();
        DB::table('sessions')->insert(['id' => 'bulk-1', 'user_id' => $a->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($this->admin)->from('/admin/users')->post('/admin/users/bulk', ['action' => 'ban', 'ids' => [$a->id, $b->id, $otherAdmin->id, $this->admin->id], 'duration' => '7', 'reason' => 'Spam links'])
            ->assertRedirect('/admin/users')
            ->assertSessionHas('status', 'Banned 2 accounts · 2 skipped (administrators, your own account or nothing to change).');

        $this->assertTrue($a->fresh()->isBanned());
        $this->assertSame('Spam links', $b->fresh()->ban_reason);
        $this->assertFalse($otherAdmin->fresh()->isBanned());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $a->id)->count());

        $log = AdminAuditLog::query()->where('action', 'users.bulk')->sole();
        $this->assertSame('ban', $log->meta['action']);
        $this->assertSame(2, $log->meta['skipped']);

        $this->post('/admin/users/bulk', ['action' => 'unban', 'ids' => [$a->id]])->assertSessionHas('status');
        $this->assertFalse($a->fresh()->isBanned());

        $this->post('/admin/users/bulk', ['action' => 'ban', 'ids' => [$a->id]])->assertSessionHasErrorsIn('bulk', ['duration', 'reason']);
        $this->post('/admin/users/bulk', ['action' => 'logout', 'ids' => []])->assertSessionHasErrorsIn('bulk', ['ids']);
        $this->post('/admin/users/bulk', ['action' => 'delete', 'ids' => [$b->id]])->assertSessionHas('status', 'Deleted 1 account.');
        $this->assertNull(User::find($b->id));

        $this->actingAs($a)->post('/admin/users/bulk', ['action' => 'logout', 'ids' => [$otherAdmin->id]])->assertForbidden();
    }

    public function test_users_csv_export_and_account_data(): void
    {
        $user = User::factory()->create(['name' => '=HYPERLINK("x")', 'phone' => '+923001234567']);
        $chat = Conversation::factory()->between($user, $this->admin)->create();
        Message::factory()->count(2)->inConversation($chat, $user)->create();

        $response = $this->actingAs($this->admin)->get('/admin/users/export?status=active')->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('ID,Name,Username,Email,Mobile,Status', $csv);
        $this->assertStringContainsString("\"'=HYPERLINK(\"\"x\"\")\"", $csv);
        $this->assertMatchesRegularExpression('/,active,user,.*,2,no,off,/', $csv);
        $this->assertSame('users.exported', AdminAuditLog::query()->latest('id')->value('action'));

        $this->get("/admin/users/{$user->id}/data")->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="account-'.$user->id.'-'.now()->format('Y-m-d').'.json"');
        $this->assertSame('user.data_exported', AdminAuditLog::query()->latest('id')->value('action'));

        $this->actingAs($user)->get('/admin/users/export')->assertForbidden();
    }

    public function test_admin_signs_out_one_browser(): void
    {
        $user = User::factory()->create();
        DB::table('sessions')->insert([
            ['id' => 'keep-me', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time(), 'user_agent' => 'Firefox'],
            ['id' => 'end-me', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time(), 'user_agent' => 'Chrome'],
        ]);
        $key = substr(hash('sha256', 'session|end-me'), 0, 40);

        $this->actingAs($this->admin)->delete("/admin/users/{$user->id}/sessions/{$key}")->assertSessionHas('status');
        $this->assertSame(['keep-me'], DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all());
        $this->delete("/admin/users/{$user->id}/sessions/{$key}")->assertNotFound();
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use App\Models\AppSetting;
use App\Models\Conversation;
use App\Models\DeviceToken;
use App\Models\User;
use App\Services\TwoStepService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Admin panel — managing accounts (edit, roles, sign out, photo, two-step) and app settings.
 */
class AdminAccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Site Admin']);
    }

    public function test_the_user_page_shows_everything_and_admins_edit_accounts(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['name' => 'Hina Tariq', 'username' => 'hina', 'profile_image' => 'avatars/hina.webp']);
        Storage::disk('public')->put('avatars/hina.webp', 'x');
        $friend = User::factory()->create(['name' => 'Omar Farooq']);
        Conversation::factory()->between($user, $friend)->create();
        DB::table('sessions')->insert(['id' => 'hina-1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time(), 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120', 'ip_address' => '39.45.1.1']);
        DeviceToken::create(['user_id' => $user->id, 'token_hash' => str_repeat('b', 64), 'platform' => 'android']);
        app(TwoStepService::class)->enable($user, '123456', Request::create('/'));

        $this->actingAs($this->admin)->get("/admin/users/{$user->id}")
            ->assertOk()
            ->assertSee('Hina Tariq')
            ->assertSee('Chrome on Windows')
            ->assertSee('Ban Hina Tariq')
            ->assertSee('Turn off two-step verification');
        // Chats have their own tab.
        $this->actingAs($this->admin)->get("/admin/users/{$user->id}?tab=chats")->assertOk()->assertSee('Omar Farooq');

        // Edit the profile.
        $this->actingAs($this->admin)->from("/admin/users/{$user->id}")->put("/admin/users/{$user->id}", [
            'name' => 'Hina T.', 'username' => '@Hina.T', 'email' => 'HINA@example.com', 'phone' => '+92 300 1112223', 'about' => 'Teacher',
        ])->assertSessionHasNoErrors()->assertSessionHas('status');
        $user->refresh();
        $this->assertSame(['Hina T.', 'hina.t', 'hina@example.com', '+923001112223', 'Teacher'], [$user->name, $user->username, $user->email, $user->phone, $user->about]);
        $this->assertStringContainsString('name, username, email, phone, about', AdminAuditLog::query()->where('action', 'user.updated')->sole()->description);
        $this->actingAs($this->admin)->put("/admin/users/{$user->id}", ['name' => 'X', 'username' => $friend->username, 'email' => 'bad', 'phone' => '12'])
            ->assertSessionHasErrorsIn('edit', ['name', 'username', 'email', 'phone']);

        // Sign out everywhere, remove the photo, turn off two-step verification.
        $this->actingAs($this->admin)->post("/admin/users/{$user->id}/logout")->assertSessionHas('status');
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertSame(0, DeviceToken::query()->where('user_id', $user->id)->count());

        $this->actingAs($this->admin)->delete("/admin/users/{$user->id}/photo")->assertSessionHas('status');
        $this->assertNull($user->fresh()->profile_image);
        Storage::disk('public')->assertMissing('avatars/hina.webp');
        $this->actingAs($this->admin)->delete("/admin/users/{$user->id}/photo")->assertNotFound();

        $this->actingAs($this->admin)->delete("/admin/users/{$user->id}/two-step")->assertSessionHas('status');
        $this->assertNull($user->fresh()->two_step_pin);

        $this->assertEqualsCanonicalizing(
            ['user.updated', 'user.logged_out', 'user.photo_removed', 'user.two_step_reset'],
            AdminAuditLog::query()->pluck('action')->all(),
        );

        // Regular people can't do any of it.
        $this->actingAs($friend)->put("/admin/users/{$user->id}", ['name' => 'Hacked'])->assertForbidden();
        $this->actingAs($friend)->post("/admin/users/{$user->id}/logout")->assertForbidden();
    }

    public function test_roles_can_be_given_and_taken_but_the_last_admin_stays(): void
    {
        $user = User::factory()->create(['name' => 'Zara']);

        $this->actingAs($this->admin)->patch("/admin/users/{$user->id}/role", ['role' => 'admin'])->assertSessionHas('status', 'Zara is now an administrator.');
        $this->assertTrue($user->fresh()->isAdmin());
        $this->actingAs($user->fresh())->get('/admin')->assertOk();

        // Other admins can't be banned until their role is removed.
        $this->actingAs($this->admin)->post("/admin/users/{$user->id}/ban", ['duration' => '1', 'reason' => 'Test'])->assertForbidden();
        $this->actingAs($this->admin)->patch("/admin/users/{$user->id}/role", ['role' => 'user'])->assertSessionHas('status');
        $this->assertFalse($user->fresh()->isAdmin());

        // Nobody changes their own role, and the only administrator can't be demoted.
        $this->actingAs($this->admin)->patch("/admin/users/{$this->admin->id}/role", ['role' => 'user'])->assertForbidden();
        $this->actingAs($this->admin)->patch("/admin/users/{$user->id}/role", ['role' => 'boss'])->assertSessionHasErrors('role');
        $this->assertSame(2, AdminAuditLog::query()->where('action', 'user.role')->count());
    }

    public function test_app_settings_close_sign_ups_and_show_a_notice_to_everyone(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/settings')->assertForbidden();
        $this->actingAs($this->admin)->get('/admin/settings')->assertOk()->assertSee('Allow new accounts');

        $this->actingAs($this->admin)->put('/admin/settings', ['registration_open' => '0', 'notice' => 'Maintenance tonight at 2 AM'])->assertSessionHas('status', 'Settings saved.');
        $this->assertFalse(AppSetting::get('registration_open'));
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'settings.updated']);

        // Everyone sees the notice in the chats.
        $this->actingAs($user)->get('/chat')->assertOk()->assertSee('Maintenance tonight at 2 AM');

        // Sign-ups are closed.
        $this->post('/logout');
        $this->get('/login')->assertOk()->assertDontSee('Create one');
        $this->get('/register')->assertRedirect(route('login'));
        $this->post('/register', ['name' => 'New Person', 'username' => 'newperson', 'email' => 'new@example.com', 'phone' => '+923009998887', 'password' => 'Password123', 'password_confirmation' => 'Password123'])
            ->assertRedirect(route('login'));
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);

        // Open again, notice removed.
        $this->actingAs($this->admin)->put('/admin/settings', ['registration_open' => '1', 'notice' => '']);
        $this->assertTrue(AppSetting::get('registration_open'));
        $this->actingAs($user)->get('/chat')->assertDontSee('Maintenance tonight');
        $this->post('/logout');
        $this->get('/register')->assertOk();
    }

    public function test_every_admin_page_opens(): void
    {
        foreach (['/admin', '/admin/users', '/admin/users?status=banned', '/admin/chats', '/admin/messages', '/admin/groups', '/admin/channels', '/admin/communities', '/admin/statuses', '/admin/audit', '/admin/settings', '/admin/reports'] as $page) {
            $this->actingAs($this->admin)->get($page)->assertOk();
        }
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Admin panel — bans (temporary and permanent) and the ban screen.
 */
class BanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Site Admin']);
    }

    public function test_a_temporary_ban_signs_the_person_out_shows_the_ban_screen_and_ends_by_itself(): void
    {
        $user = User::factory()->online()->create(['name' => 'Spammy Sam', 'email' => 'sam@example.com']);
        DB::table('sessions')->insert(['id' => 'sam-phone', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        DeviceToken::create(['user_id' => $user->id, 'token_hash' => str_repeat('a', 64), 'platform' => 'android']);

        $this->actingAs($this->admin)->from("/admin/users/{$user->id}")
            ->post("/admin/users/{$user->id}/ban", ['duration' => '7', 'reason' => 'Sending loan ads to everyone'])
            ->assertRedirect("/admin/users/{$user->id}")
            ->assertSessionHas('status', 'Spammy Sam is banned for 7 days.');

        $user->refresh();
        $this->assertSame(User::STATUS_BANNED, $user->status);
        $this->assertSame('Sending loan ads to everyone', $user->ban_reason);
        $this->assertTrue($user->banned_until->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));
        $this->assertSame($this->admin->id, $user->banned_by);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertSame(0, $user->deviceTokens()->count());
        $this->assertFalse($user->is_online);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'user.banned', 'target_type' => 'User', 'target_id' => $user->id, 'admin_id' => $this->admin->id]);

        // Their next visit: signed out, ban screen with the reason and end date.
        $this->actingAs($user)->get('/chat')->assertRedirect(route('account.banned'));
        $this->get('/account/banned')->assertOk()
            ->assertSee('Your account has been banned')
            ->assertSee('Sending loan ads to everyone')
            ->assertSee($user->banned_until->format('j M Y'));

        // The app's requests get a clear answer.
        $this->actingAs($user->fresh())->getJson('/conversations')->assertForbidden()->assertJsonPath('banned', true);

        // Signing in with the right password goes to the ban screen too.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->post('/login', ['login' => 'sam@example.com', 'password' => 'Password1'])->assertRedirect(route('account.banned'));
        $this->assertGuest();
        $this->post('/login', ['login' => 'sam@example.com', 'password' => 'wrong-one'])->assertSessionHasErrors('login');

        // Without a ban in the session the screen just goes to sign in.
        $this->flushSession();
        $this->get('/account/banned')->assertRedirect(route('login'));

        // A week later the ban is over: signing in works again.
        $this->travel(8)->days();
        $this->post('/login', ['login' => 'sam@example.com', 'password' => 'Password1'])->assertRedirect(route('chat.index'));
        $this->assertAuthenticatedAs($user->fresh());
        $this->assertSame(User::STATUS_ACTIVE, $user->fresh()->status);
        $this->assertNull($user->fresh()->ban_reason);
    }

    public function test_permanent_bans_are_lifted_by_an_admin_and_temporary_ones_by_the_scheduler(): void
    {
        $forever = User::factory()->create(['name' => 'Forever Banned']);
        $week = User::factory()->create(['name' => 'Week Banned']);

        $this->actingAs($this->admin)->post("/admin/users/{$forever->id}/ban", ['duration' => 'permanent', 'reason' => 'Threats'])
            ->assertSessionHas('status', 'Forever Banned is banned permanently.');
        $this->actingAs($this->admin)->post("/admin/users/{$week->id}/ban", ['duration' => '1', 'reason' => 'Rude'])->assertSessionHasNoErrors();
        $this->assertNull($forever->fresh()->banned_until);

        // The ban screen says it's permanent.
        $this->actingAs($forever->fresh())->get('/settings');
        $this->get('/account/banned')->assertSee('Permanent')->assertSee('Threats');

        $this->travel(2)->days();
        $this->artisan('chat:lift-bans')->expectsOutput('Lifted 1 ban(s) whose time is up.')->assertSuccessful();
        $this->assertSame(User::STATUS_ACTIVE, $week->fresh()->status);
        $this->assertSame(User::STATUS_BANNED, $forever->fresh()->status);

        $this->actingAs($this->admin)->delete("/admin/users/{$forever->id}/ban")->assertSessionHas('status', 'Forever Banned can use the app again.');
        $this->assertSame(User::STATUS_ACTIVE, $forever->fresh()->status);
        $this->assertSame(2, AdminAuditLog::query()->where('action', 'user.banned')->count());
        $this->assertSame(1, AdminAuditLog::query()->where('action', 'user.unbanned')->count());

        // Filter and badge in the users list.
        $this->actingAs($this->admin)->post("/admin/users/{$week->id}/ban", ['duration' => '30', 'reason' => 'Again']);
        $this->actingAs($this->admin)->get('/admin/users?status=banned')->assertOk()->assertSee('Week Banned')->assertDontSee('Forever Banned')->assertSee('Banned until');
    }

    public function test_bans_need_a_reason_and_a_length_and_never_touch_admins(): void
    {
        $user = User::factory()->create();
        $otherAdmin = User::factory()->admin()->create();

        $this->actingAs($this->admin)->post("/admin/users/{$user->id}/ban", ['duration' => '5', 'reason' => ''])
            ->assertSessionHasErrorsIn('ban', ['duration', 'reason']);
        $this->actingAs($this->admin)->post("/admin/users/{$otherAdmin->id}/ban", ['duration' => '1', 'reason' => 'No'])->assertForbidden();
        $this->actingAs($this->admin)->post("/admin/users/{$this->admin->id}/ban", ['duration' => '1', 'reason' => 'No'])->assertForbidden();
        $this->actingAs($user)->post("/admin/users/{$otherAdmin->id}/ban", ['duration' => '1', 'reason' => 'No'])->assertForbidden();
        $this->actingAs($this->admin)->delete("/admin/users/{$user->id}/ban")->assertNotFound();

        // The status menu can't be used to ban without a reason.
        $this->actingAs($this->admin)->patch("/admin/users/{$user->id}/status", ['status' => 'banned'])->assertSessionHasErrors('status');
        $this->assertSame(User::STATUS_ACTIVE, $user->fresh()->status);
    }
}

<?php

namespace Tests\Feature;

use App\Models\BlockedUser;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['name' => 'Site Admin']);
    }

    public function test_only_admins_can_open_the_admin_panel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin')->assertForbidden();
        $this->actingAs($user)->get('/admin/users')->assertForbidden();

        auth()->logout();
        $this->get('/admin')->assertRedirect(route('login'));

        $this->actingAs($this->admin)->get('/admin')->assertOk()->assertSee('Dashboard');
    }

    public function test_dashboard_shows_platform_statistics_without_message_content(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        User::factory()->online()->create();
        $conversation = Conversation::factory()->between($a, $b)->create();
        Message::factory()->inConversation($conversation, $a)->create(['message' => 'TOP SECRET CONTENT']);
        BlockedUser::create(['user_id' => $a->id, 'blocked_user_id' => $b->id]);

        $response = $this->actingAs($this->admin)->get('/admin')->assertOk();

        $stats = $response->viewData('stats');
        $this->assertSame(4, $stats['total_users']);
        $this->assertSame(1, $stats['online_users']); // activity of the current request is recorded after the response
        $this->assertSame(1, $stats['total_conversations']);
        $this->assertSame(1, $stats['total_messages']);
        $this->assertSame(4, $stats['new_users']);
        $this->assertSame(1, $stats['blocked_users']);

        $response->assertDontSee('TOP SECRET CONTENT');
        $this->actingAs($this->admin)->get("/admin/users/{$a->id}")->assertOk()->assertDontSee('TOP SECRET CONTENT');
    }

    public function test_users_can_be_searched_and_filtered(): void
    {
        User::factory()->create(['name' => 'Findable Person', 'username' => 'findme']);
        User::factory()->suspended()->create(['name' => 'Suspended Person']);

        $this->actingAs($this->admin)->get('/admin/users?q=findme')
            ->assertOk()->assertSee('Findable Person')->assertDontSee('Suspended Person');

        $this->actingAs($this->admin)->get('/admin/users?status=suspended')
            ->assertOk()->assertSee('Suspended Person')->assertDontSee('Findable Person');

        $this->actingAs($this->admin)->get('/admin/users?status=bogus')->assertSessionHasErrors('status');
    }

    public function test_admin_can_suspend_deactivate_and_activate_users(): void
    {
        $user = User::factory()->online()->create();
        DB::table('sessions')->insert(['id' => 'session-1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($this->admin)
            ->from('/admin/users')
            ->patch("/admin/users/{$user->id}/status", ['status' => 'suspended'])
            ->assertRedirect('/admin/users')
            ->assertSessionHas('status');

        $this->assertSame(User::STATUS_SUSPENDED, $user->fresh()->status);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertFalse($user->fresh()->is_online);

        // The suspended user is signed out on their next request.
        $this->actingAs($user->fresh())->get('/chat')->assertRedirect(route('login'));

        $this->actingAs($this->admin)->patch("/admin/users/{$user->id}/status", ['status' => 'inactive']);
        $this->assertSame(User::STATUS_INACTIVE, $user->fresh()->status);

        $this->actingAs($this->admin)->patch("/admin/users/{$user->id}/status", ['status' => 'active']);
        $this->assertSame(User::STATUS_ACTIVE, $user->fresh()->status);

        $this->actingAs($this->admin)->patch("/admin/users/{$user->id}/status", ['status' => 'banana'])->assertSessionHasErrors('status');
    }

    public function test_admins_cannot_change_their_own_or_other_admin_accounts(): void
    {
        $otherAdmin = User::factory()->admin()->create();

        $this->actingAs($this->admin)->patch("/admin/users/{$this->admin->id}/status", ['status' => 'suspended'])->assertForbidden();
        $this->actingAs($this->admin)->patch("/admin/users/{$otherAdmin->id}/status", ['status' => 'suspended'])->assertForbidden();
        $this->actingAs($this->admin)->delete("/admin/users/{$this->admin->id}")->assertForbidden();

        $this->assertSame(User::STATUS_ACTIVE, $this->admin->fresh()->status);
    }

    public function test_regular_users_cannot_use_admin_actions(): void
    {
        [$user, $victim] = User::factory()->count(2)->create();

        $this->actingAs($user)->patch("/admin/users/{$victim->id}/status", ['status' => 'suspended'])->assertForbidden();
        $this->actingAs($user)->delete("/admin/users/{$victim->id}")->assertForbidden();

        $this->assertNotNull($victim->fresh());
    }

    public function test_deleting_a_user_removes_their_conversations_messages_and_files(): void
    {
        Storage::fake('chat');
        Storage::fake('public');

        [$user, $friend, $bystander] = User::factory()->count(3)->create();
        $withFriend = Conversation::factory()->between($user, $friend)->create();
        $unrelated = Conversation::factory()->between($friend, $bystander)->create();

        // A message with an attachment, a reply chain and a notification.
        $this->actingAs($user)->post("/conversations/{$withFriend->id}/messages", [
            'attachment' => UploadedFile::fake()->image('photo.jpg', 300, 300),
        ], ['Accept' => 'application/json'])->assertCreated();
        $original = Message::where('conversation_id', $withFriend->id)->sole();
        $this->actingAs($friend)->postJson("/conversations/{$withFriend->id}/messages", ['message' => 'reply', 'reply_to_id' => $original->id])->assertCreated();
        $keep = Message::factory()->inConversation($unrelated, $bystander)->create();
        BlockedUser::create(['user_id' => $friend->id, 'blocked_user_id' => $user->id]);

        $files = [$original->attachment, $original->attachment_meta['thumbnail']];

        $this->actingAs($this->admin)->delete("/admin/users/{$user->id}")
            ->assertRedirect(route('admin.users'))
            ->assertSessionHas('status');

        $this->assertNull(User::find($user->id));
        $this->assertNull(Conversation::find($withFriend->id));
        $this->assertSame(0, Message::where('conversation_id', $withFriend->id)->count());
        $this->assertSame(0, BlockedUser::count());
        $this->assertSame(0, DB::table('notifications')->where('data->sender->id', $user->id)->count());
        foreach ($files as $file) {
            Storage::disk('chat')->assertMissing($file);
        }

        // Other users' data is untouched.
        $this->assertNotNull($friend->fresh());
        $this->assertNotNull($keep->fresh());
        $this->assertNotNull($unrelated->fresh());
    }
}

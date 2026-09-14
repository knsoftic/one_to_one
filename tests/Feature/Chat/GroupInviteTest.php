<?php

namespace Tests\Feature\Chat;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * G3 — Joining a group with an invite link (or its QR code).
 */
class GroupInviteTest extends TestCase
{
    use RefreshDatabase;

    private User $ayesha;

    private User $bilal;

    private Conversation $group;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ayesha = User::factory()->create(['name' => 'Ayesha']);
        $this->bilal = User::factory()->create(['name' => 'Bilal']);
        $id = $this->actingAs($this->ayesha)->postJson('/groups', ['name' => 'Cricket Club', 'member_ids' => [$this->bilal->id]])->json('id');
        $this->group = Conversation::findOrFail($id);
    }

    public function test_admins_get_and_reset_the_invite_link(): void
    {
        $this->actingAs($this->bilal)->getJson("/groups/{$this->group->id}/invite")->assertForbidden();

        $first = $this->actingAs($this->ayesha)->getJson("/groups/{$this->group->id}/invite")->assertOk()->json();
        $this->assertStringContainsString('/join/'.$first['token'], $first['url']);
        $this->assertSame($first['token'], $this->actingAs($this->ayesha)->getJson("/groups/{$this->group->id}/invite")->json('token'));

        $reset = $this->actingAs($this->ayesha)->postJson("/groups/{$this->group->id}/invite")->assertOk()->json();
        $this->assertNotSame($first['token'], $reset['token']);

        // The old link no longer works.
        $this->actingAs(User::factory()->create())->postJson("/join/{$first['token']}")->assertStatus(410);
        $this->actingAs(User::factory()->create())->get("/join/{$first['token']}")
            ->assertOk()->assertViewHas('chatConfig', fn (array $config) => $config['groupInvite']['valid'] === false);
    }

    public function test_people_see_the_group_and_join_with_the_link(): void
    {
        $token = $this->actingAs($this->ayesha)->getJson("/groups/{$this->group->id}/invite")->json('token');
        $sara = User::factory()->create(['name' => 'Sara']);

        $this->actingAs($sara)->get("/join/{$token}")
            ->assertOk()
            ->assertViewHas('chatConfig', fn (array $config) => $config['groupInvite']['name'] === 'Cricket Club'
                && $config['groupInvite']['member_count'] === 2
                && $config['groupInvite']['is_member'] === false);

        $this->actingAs($sara)->postJson("/join/{$token}")
            ->assertOk()
            ->assertJsonPath('group.is_member', true)
            ->assertJsonPath('group.my_role', 'member')
            ->assertJsonPath('group.member_count', 3)
            ->assertJsonPath('last_message.preview', "Sara joined using this group's invite link");

        // Joining twice changes nothing.
        $this->actingAs($sara)->postJson("/join/{$token}")->assertOk()->assertJsonPath('group.member_count', 3);
        $this->actingAs($sara)->postJson("/conversations/{$this->group->id}/messages", ['message' => 'Salam!'])->assertCreated();
    }

    public function test_people_who_are_not_signed_in_sign_in_first(): void
    {
        $this->group->forceFill(['invite_token' => str_repeat('g', 22)])->save();

        $this->app['auth']->forgetGuards();
        $this->get('/join/'.str_repeat('g', 22))->assertRedirect(route('login'));
    }

    public function test_deleted_or_full_groups_cannot_be_joined(): void
    {
        $token = $this->actingAs($this->ayesha)->getJson("/groups/{$this->group->id}/invite")->json('token');

        config(['chat.groups.max_members' => 3]);
        $this->actingAs(User::factory()->create())->postJson("/join/{$token}")->assertOk();
        $this->actingAs(User::factory()->create())->postJson("/join/{$token}")
            ->assertUnprocessable()->assertJsonPath('message', 'This group is full.');

        $this->actingAs($this->ayesha)->deleteJson("/groups/{$this->group->id}")->assertOk();
        $this->actingAs(User::factory()->create())->postJson("/join/{$token}")->assertStatus(410);
    }
}

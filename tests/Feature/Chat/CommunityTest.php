<?php

namespace Tests\Feature\Chat;

use App\Models\Community;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * G10 — Communities.
 */
class CommunityTest extends TestCase
{
    use RefreshDatabase;

    private User $ayesha;

    private User $bilal;

    private User $sara;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->ayesha, $this->bilal, $this->sara] = [
            User::factory()->create(['name' => 'Ayesha']),
            User::factory()->create(['name' => 'Bilal']),
            User::factory()->create(['name' => 'Sara']),
        ];
    }

    public function test_a_community_has_an_announcement_group_and_brings_in_the_people_of_its_groups(): void
    {
        $cricket = $this->group($this->ayesha, 'Cricket', [$this->bilal]);

        $community = $this->actingAs($this->ayesha)->postJson('/communities', [
            'name' => 'Model Town Society',
            'description' => 'Everything about our block',
            'group_ids' => [$cricket->id],
        ])
            ->assertCreated()
            ->assertJsonPath('name', 'Model Town Society')
            ->assertJsonPath('is_admin', true)
            ->assertJsonPath('member_count', 2)
            ->assertJsonPath('groups.0.name', 'Cricket')
            ->json();

        $announcement = Conversation::findOrFail($community['announcement_id']);
        $this->assertTrue($announcement->is_announcement);
        $this->assertTrue($announcement->only_admins_send);

        // Bilal was in Cricket, so he is in the community: he reads announcements but cannot post.
        $this->actingAs($this->bilal)->getJson('/communities')->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_admin', false);
        $this->actingAs($this->bilal)->getJson("/conversations/{$announcement->id}")
            ->assertJsonPath('group.community.is_announcement', true)
            ->assertJsonPath('group.can_send', false);
        $this->actingAs($this->bilal)->postJson("/conversations/{$announcement->id}/messages", ['message' => 'hi'])->assertForbidden();
        $this->actingAs($this->ayesha)->postJson("/conversations/{$announcement->id}/messages", ['message' => 'Water off tomorrow 10–12'])->assertCreated();
        $this->actingAs($this->bilal)->getJson('/conversations')->assertJsonPath('0.id', $announcement->id)->assertJsonPath('0.unread_count', 1);

        $this->actingAs($this->ayesha)->getJson("/conversations/{$cricket->id}")->assertJsonPath('group.community.name', 'Model Town Society');
        $this->assertSame('Ayesha added this group to the community "Model Town Society"', $cricket->messages()->latest('id')->first()->systemText());

        // People added to a community group later join the community too.
        $this->actingAs($this->ayesha)->postJson("/groups/{$cricket->id}/members", ['user_ids' => [$this->sara->id]])->assertOk();
        $this->actingAs($this->sara)->getJson("/communities/{$community['id']}")->assertOk()->assertJsonPath('member_count', 3);

        // Strangers see nothing.
        $this->actingAs(User::factory()->create())->getJson("/communities/{$community['id']}")->assertNotFound();
    }

    public function test_admins_create_link_and_unlink_groups_and_members_join_them(): void
    {
        $community = $this->community($this->ayesha, 'Neighbours');
        $announcementId = $community->announcement()->value('id');
        $this->actingAs($this->ayesha)->postJson("/groups/{$announcementId}/members", ['user_ids' => [$this->bilal->id]])->assertOk();

        $this->actingAs($this->bilal)->postJson("/communities/{$community->id}/groups", ['name' => 'Parking'])->assertForbidden();
        $parking = $this->actingAs($this->ayesha)->postJson("/communities/{$community->id}/groups", ['name' => 'Parking'])
            ->assertCreated()
            ->assertJsonPath('group.community.id', $community->id)
            ->assertJsonPath('group.member_count', 1)
            ->json('id');

        // Bilal sees the group and joins it.
        $this->actingAs($this->bilal)->getJson("/communities/{$community->id}")
            ->assertJsonPath('groups.0.name', 'Parking')
            ->assertJsonPath('groups.0.is_member', false);
        $this->actingAs($this->bilal)->postJson("/communities/{$community->id}/groups/{$parking}/join")
            ->assertOk()
            ->assertJsonPath('group.is_member', true)
            ->assertJsonPath('last_message.preview', 'Bilal joined from the community');

        // A group of someone else cannot be linked; a group in another community neither.
        $saraGroup = $this->group($this->sara, 'Sara group', [$this->ayesha]);
        $this->actingAs($this->ayesha)->postJson("/communities/{$community->id}/groups/{$saraGroup->id}")
            ->assertForbidden()->assertJsonPath('message', 'Only admins of a group can add it to a community.');
        $other = $this->community($this->ayesha, 'Other');
        $this->actingAs($this->ayesha)->postJson("/communities/{$other->id}/groups/{$parking}")
            ->assertUnprocessable()->assertJsonPath('message', 'This group is already in another community.');

        $this->actingAs($this->ayesha)->deleteJson("/communities/{$community->id}/groups/{$parking}")->assertOk()->assertJsonCount(0, 'groups');
        $this->assertNull(Conversation::find($parking)->community_id);
    }

    public function test_invite_link_leaving_removing_and_deleting_a_community(): void
    {
        $community = $this->community($this->ayesha, 'Old Students');
        $group = $this->actingAs($this->ayesha)->postJson("/communities/{$community->id}/groups", ['name' => 'Batch 2010'])->json('id');

        $this->actingAs($this->bilal)->getJson("/communities/{$community->id}/invite")->assertNotFound();
        $token = $this->actingAs($this->ayesha)->getJson("/communities/{$community->id}/invite")->assertOk()->json('token');

        $this->actingAs($this->bilal)->get("/community/{$token}")
            ->assertOk()->assertViewHas('chatConfig', fn (array $config) => $config['communityInvite']['name'] === 'Old Students' && $config['communityInvite']['is_member'] === false);
        $this->actingAs($this->bilal)->postJson("/community/{$token}")->assertOk()->assertJsonPath('is_member', true);
        $this->actingAs($this->sara)->postJson("/community/{$token}")->assertOk();
        $this->actingAs($this->bilal)->postJson("/communities/{$community->id}/groups/{$group}/join")->assertOk();

        // Leaving the community leaves its groups too.
        $this->actingAs($this->bilal)->postJson("/communities/{$community->id}/leave")->assertOk();
        $this->assertFalse(Conversation::find($group)->isActiveMember($this->bilal));
        $this->actingAs($this->bilal)->getJson('/communities')->assertJsonCount(0, 'data');

        // Admins remove people from everything; the last admin cannot just leave.
        $this->actingAs($this->ayesha)->deleteJson("/communities/{$community->id}/members/{$this->sara->id}")->assertOk()->assertJsonPath('member_count', 1);
        $this->actingAs($this->sara)->getJson("/communities/{$community->id}")->assertNotFound();

        $this->actingAs($this->ayesha)->deleteJson("/communities/{$community->id}")->assertOk();
        $this->assertNull(Community::find($community->id));
        $this->assertNull(Conversation::find($group)->community_id);
        $this->actingAs($this->bilal)->postJson("/community/{$token}")->assertStatus(410);
    }

    private function community(User $owner, string $name): Community
    {
        return Community::findOrFail($this->actingAs($owner)->postJson('/communities', ['name' => $name])->assertCreated()->json('id'));
    }

    /**
     * @param  list<User>  $members
     */
    private function group(User $owner, string $name, array $members): Conversation
    {
        return Conversation::findOrFail($this->actingAs($owner)->postJson('/groups', ['name' => $name, 'member_ids' => collect($members)->pluck('id')->all()])->assertCreated()->json('id'));
    }
}

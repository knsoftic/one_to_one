<?php

namespace Tests\Feature\Chat;

use App\Models\Community;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\MessageService;
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

    public function test_members_of_a_community_do_not_see_each_other_but_admins_see_everyone(): void
    {
        $community = $this->community($this->ayesha, 'Model Town Society');
        $announcement = $community->announcement()->value('id');
        $token = $this->actingAs($this->ayesha)->getJson("/communities/{$community->id}/invite")->json('token');
        $this->actingAs($this->bilal)->postJson("/community/{$token}")->assertOk();
        $this->actingAs($this->sara)->postJson("/community/{$token}")->assertOk();
        $usman = User::factory()->create(['name' => 'Usman']);
        $this->actingAs($this->ayesha)->postJson("/groups/{$announcement}/members", ['user_ids' => [$usman->id]])->assertOk();

        // Bilal sees how many people there are, but only the admin and himself.
        $bilalView = $this->actingAs($this->bilal)->getJson("/conversations/{$announcement}")
            ->assertOk()
            ->assertJsonPath('group.members_hidden', true)
            ->assertJsonPath('group.member_count', 4)
            ->json('group.members');
        $this->assertEqualsCanonicalizing([$this->ayesha->id, $this->bilal->id], array_column(array_column($bilalView, 'user'), 'id'));

        // The admin sees everyone.
        $adminView = $this->actingAs($this->ayesha)->getJson("/conversations/{$announcement}")->assertJsonPath('group.members_hidden', false)->json('group.members');
        $this->assertCount(4, $adminView);

        // Joining, being added and leaving are not announced to the community.
        $post = $this->actingAs($this->ayesha)->postJson("/conversations/{$announcement}/messages", ['message' => 'Water off tomorrow 10–12'])->json('id');
        $poll = $this->actingAs($this->ayesha)->postJson("/conversations/{$announcement}/messages", ['poll' => ['question' => 'Meeting day?', 'options' => ['Sat', 'Sun']]])->json('id');
        $this->actingAs($this->sara)->postJson("/communities/{$community->id}/leave")->assertOk();
        $history = $this->actingAs($this->bilal)->getJson("/conversations/{$announcement}/messages")->assertOk();
        foreach (['Sara', 'Usman', 'joined', 'added', 'left'] as $text) {
            $this->assertStringNotContainsString($text, json_encode($history->json('data')));
        }

        // Reactions and poll votes: members only see their own, the admin sees who.
        $this->actingAs($usman)->putJson("/messages/{$post}/reaction", ['emoji' => '👍'])->assertOk();
        $this->actingAs($this->bilal)->putJson("/messages/{$post}/reaction", ['emoji' => '👍'])->assertOk()
            ->assertJsonPath('reactions.0.count', 2)
            ->assertJsonPath('reactions.0.user_ids', [$this->bilal->id]);
        $this->actingAs($usman)->putJson("/messages/{$poll}/vote", ['options' => [1]])->assertOk();
        $this->actingAs($this->bilal)->putJson("/messages/{$poll}/vote", ['options' => [1]])->assertOk()
            ->assertJsonPath('poll.options.0.count', 2)
            ->assertJsonPath('poll.options.0.voter_ids', [$this->bilal->id]);
        $adminHistory = collect($this->actingAs($this->ayesha)->getJson("/conversations/{$announcement}/messages")->json('data'))->keyBy('id');
        $this->assertEqualsCanonicalizing([$usman->id, $this->bilal->id], $adminHistory[$post]['reactions'][0]['user_ids']);
        $this->assertEqualsCanonicalizing([$usman->id, $this->bilal->id], $adminHistory[$poll]['poll']['options'][0]['voter_ids']);

        // Removing someone is quiet too; the removed person keeps what they already had.
        $this->actingAs($this->ayesha)->deleteJson("/communities/{$community->id}/members/{$usman->id}")->assertOk();
        $this->assertStringNotContainsString('Usman', json_encode($this->actingAs($this->bilal)->getJson("/conversations/{$announcement}/messages")->json('data')));
        $this->assertSame($poll, Conversation::findOrFail($announcement)->members()->where('user_id', $usman->id)->value('visible_until_message_id'));

        // A group inside the community is a normal group: its members see each other.
        $parking = $this->actingAs($this->ayesha)->postJson("/communities/{$community->id}/groups", ['name' => 'Parking'])->json('id');
        $this->actingAs($this->bilal)->postJson("/communities/{$community->id}/groups/{$parking}/join")->assertOk()
            ->assertJsonPath('group.members_hidden', false)
            ->assertJsonCount(2, 'group.members');
    }

    public function test_old_member_notices_are_removed_from_community_announcements(): void
    {
        $community = $this->community($this->ayesha, 'Old Students');
        $announcement = Conversation::findOrFail($community->announcement()->value('id'));
        $cricket = $this->group($this->ayesha, 'Cricket', [$this->bilal]);
        $messages = app(MessageService::class);
        $keep = $this->actingAs($this->ayesha)->postJson("/conversations/{$announcement->id}/messages", ['message' => 'Welcome'])->json('id');
        $joined = $messages->systemNotice($this->bilal, $announcement, ['event' => 'member_joined_link', 'actor' => ['id' => $this->bilal->id, 'name' => 'Bilal']]);
        $left = $messages->systemNotice($this->sara, $announcement, ['event' => 'member_left', 'actor' => ['id' => $this->sara->id, 'name' => 'Sara']]);
        $announcement->forceFill(['last_message_id' => $left->id])->save();
        $groupNotice = $messages->systemNotice($this->bilal, $cricket, ['event' => 'member_left', 'actor' => ['id' => $this->bilal->id, 'name' => 'Bilal']]);

        (require database_path('migrations/2026_09_24_000001_remove_member_notices_from_community_announcements.php'))->up();

        $this->assertNull(Message::find($joined->id));
        $this->assertNull(Message::find($left->id));
        $this->assertNotNull(Message::find($keep));
        $this->assertSame($keep, $announcement->fresh()->last_message_id);
        // Ordinary groups keep their notices.
        $this->assertNotNull(Message::find($groupNotice->id));
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

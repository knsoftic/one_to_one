<?php

namespace Tests\Feature\Chat;

use App\Events\GroupUpdated;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * G2 — admins and removing people, G5 — group settings, G8 — exit and delete group.
 */
class GroupAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $ayesha;

    private User $bilal;

    private User $sara;

    private Conversation $group;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->ayesha, $this->bilal, $this->sara] = [
            User::factory()->create(['name' => 'Ayesha']),
            User::factory()->create(['name' => 'Bilal']),
            User::factory()->create(['name' => 'Sara']),
        ];

        $id = $this->actingAs($this->ayesha)->postJson('/groups', ['name' => 'Family', 'member_ids' => [$this->bilal->id, $this->sara->id]])->json('id');
        $this->group = Conversation::findOrFail($id);
    }

    /* ------------------------------------------------------------------ */
    /* G2 */
    /* ------------------------------------------------------------------ */

    public function test_admins_make_and_dismiss_admins(): void
    {
        Event::fake([GroupUpdated::class]);

        $this->actingAs($this->bilal)->patchJson("/groups/{$this->group->id}/members/{$this->sara->id}", ['role' => 'admin'])
            ->assertForbidden()->assertJsonPath('message', 'Only group admins can do this.');

        $this->actingAs($this->ayesha)->patchJson("/groups/{$this->group->id}/members/{$this->bilal->id}", ['role' => 'admin'])
            ->assertOk()->assertJsonPath('group.members.1.role', 'admin');
        Event::assertDispatched(GroupUpdated::class);

        // The new admin can manage others, but not dismiss the creator.
        $this->actingAs($this->bilal)->patchJson("/groups/{$this->group->id}/members/{$this->sara->id}", ['role' => 'admin'])->assertOk();
        $this->actingAs($this->bilal)->patchJson("/groups/{$this->group->id}/members/{$this->ayesha->id}", ['role' => 'member'])
            ->assertForbidden()->assertJsonPath('message', 'The group creator cannot be dismissed as admin.');
        $this->actingAs($this->bilal)->patchJson("/groups/{$this->group->id}/members/{$this->sara->id}", ['role' => 'member'])->assertOk();

        $this->actingAs($this->sara)->getJson("/conversations/{$this->group->id}")->assertJsonPath('group.my_role', 'member');
        $this->actingAs($this->bilal)->getJson("/conversations/{$this->group->id}")->assertJsonPath('group.my_role', 'admin');
    }

    public function test_admins_remove_people_who_then_only_read_the_past(): void
    {
        $before = $this->say($this->ayesha, 'Before');

        $this->actingAs($this->bilal)->deleteJson("/groups/{$this->group->id}/members/{$this->sara->id}")->assertForbidden();
        $this->actingAs($this->ayesha)->deleteJson("/groups/{$this->group->id}/members/{$this->ayesha->id}")->assertUnprocessable();

        $this->actingAs($this->ayesha)->deleteJson("/groups/{$this->group->id}/members/{$this->sara->id}")
            ->assertOk()
            ->assertJsonPath('group.member_count', 2)
            ->assertJsonPath('last_message.preview', 'Ayesha removed Sara');

        $after = $this->say($this->ayesha, 'After');

        // Sara sees the chat up to her removal, read-only.
        $ids = collect($this->actingAs($this->sara)->getJson("/conversations/{$this->group->id}/messages")->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($before));
        $this->assertFalse($ids->contains($after));
        $this->actingAs($this->sara)->getJson("/conversations/{$this->group->id}")
            ->assertJsonPath('group.is_member', false)
            ->assertJsonPath('group.can_send', false);
        $this->actingAs($this->sara)->postJson("/conversations/{$this->group->id}/messages", ['message' => 'Hello?'])
            ->assertForbidden()->assertJsonPath('message', "You can't send messages to this group because you're no longer a member.");
        $this->actingAs($this->sara)->putJson("/messages/{$before}/reaction", ['emoji' => '👍'])->assertForbidden();

        // The creator cannot be removed; removed people can be added back.
        $this->actingAs($this->ayesha)->patchJson("/groups/{$this->group->id}/members/{$this->bilal->id}", ['role' => 'admin'])->assertOk();
        $this->actingAs($this->bilal)->deleteJson("/groups/{$this->group->id}/members/{$this->ayesha->id}")
            ->assertForbidden()->assertJsonPath('message', 'The group creator cannot be removed.');
        $this->actingAs($this->bilal)->postJson("/groups/{$this->group->id}/members", ['user_ids' => [$this->sara->id]])->assertOk();
        $this->actingAs($this->sara)->postJson("/conversations/{$this->group->id}/messages", ['message' => 'Back!'])->assertCreated();
    }

    /* ------------------------------------------------------------------ */
    /* G5 */
    /* ------------------------------------------------------------------ */

    public function test_only_admins_can_send_or_edit_info_when_the_group_says_so(): void
    {
        $this->actingAs($this->bilal)->patchJson("/groups/{$this->group->id}/settings", ['only_admins_send' => true])->assertForbidden();

        $this->actingAs($this->ayesha)->patchJson("/groups/{$this->group->id}/settings", ['only_admins_send' => true, 'only_admins_edit' => true])
            ->assertOk()
            ->assertJsonPath('group.only_admins_send', true)
            ->assertJsonPath('group.only_admins_edit', true);

        $this->actingAs($this->bilal)->getJson("/conversations/{$this->group->id}")
            ->assertJsonPath('group.can_send', false)
            ->assertJsonPath('group.can_edit_info', false);
        $this->actingAs($this->bilal)->postJson("/conversations/{$this->group->id}/messages", ['message' => 'Hi'])
            ->assertForbidden()->assertJsonPath('message', 'Only admins can send messages to this group.');
        $this->actingAs($this->bilal)->post("/groups/{$this->group->id}", ['name' => 'Mine now'], ['Accept' => 'application/json'])
            ->assertForbidden()->assertJsonPath('message', "Only admins can edit this group's info.");
        $this->actingAs($this->bilal)->postJson("/groups/{$this->group->id}/members", ['user_ids' => [User::factory()->create()->id]])->assertForbidden();
        $this->actingAs($this->bilal)->putJson("/conversations/{$this->group->id}/disappearing", ['seconds' => 86400])->assertForbidden();

        $message = $this->say($this->ayesha, 'Rules: be nice');
        $this->actingAs($this->bilal)->putJson("/messages/{$message}/pin", ['duration' => 86400])->assertForbidden();
        $this->actingAs($this->ayesha)->putJson("/messages/{$message}/pin", ['duration' => 86400])->assertSuccessful();

        $notices = Message::query()->where('conversation_id', $this->group->id)->where('message_type', 'system')->orderBy('id')->get()->map->systemText()->slice(1)->values()->all();
        $this->assertSame([
            "Ayesha changed this group's settings to allow only admins to send messages",
            "Ayesha changed this group's settings to allow only admins to edit this group's info",
        ], $notices);

        // Turning it back on lets everyone write again.
        $this->actingAs($this->ayesha)->patchJson("/groups/{$this->group->id}/settings", ['only_admins_send' => false])->assertOk();
        $this->say($this->bilal, 'Finally');
    }

    /* ------------------------------------------------------------------ */
    /* G8 */
    /* ------------------------------------------------------------------ */

    public function test_exiting_a_group_and_the_next_admin(): void
    {
        // A member of the group must exit before deleting the chat.
        $this->actingAs($this->bilal)->deleteJson("/conversations/{$this->group->id}")
            ->assertUnprocessable()->assertJsonPath('message', 'Exit the group before deleting it.');

        $this->actingAs($this->ayesha)->postJson("/groups/{$this->group->id}/leave")
            ->assertOk()
            ->assertJsonPath('group.is_member', false)
            ->assertJsonPath('last_message.preview', 'Ayesha left');

        // The last admin left: the longest-standing member becomes admin.
        $this->assertSame(ConversationMember::ROLE_ADMIN, $this->group->memberFor($this->bilal)->role);
        $this->actingAs($this->ayesha)->postJson("/groups/{$this->group->id}/leave")->assertUnprocessable();

        // Now Ayesha can delete the chat for herself.
        $this->actingAs($this->ayesha)->deleteJson("/conversations/{$this->group->id}")->assertOk();
        $this->actingAs($this->ayesha)->getJson('/conversations')->assertJsonCount(0);

        $this->actingAs($this->bilal)->postJson("/groups/{$this->group->id}/leave")->assertOk();
        $this->actingAs($this->sara)->postJson("/groups/{$this->group->id}/leave")->assertOk();
        $this->assertNotNull($this->group->fresh()->ended_at);
    }

    public function test_admins_delete_the_group_for_everyone(): void
    {
        $this->actingAs($this->bilal)->deleteJson("/groups/{$this->group->id}")->assertForbidden();

        $this->actingAs($this->ayesha)->deleteJson("/groups/{$this->group->id}")
            ->assertOk()
            ->assertJsonPath('group.ended', true)
            ->assertJsonPath('group.member_count', 0);

        foreach ([$this->bilal, $this->sara] as $member) {
            $this->actingAs($member)->getJson("/conversations/{$this->group->id}")
                ->assertJsonPath('group.is_member', false)
                ->assertJsonPath('group.ended', true)
                ->assertJsonPath('last_message.preview', 'Ayesha deleted this group');
            $this->actingAs($member)->postJson("/conversations/{$this->group->id}/messages", ['message' => 'Hello?'])->assertForbidden();
            $this->actingAs($member)->deleteJson("/conversations/{$this->group->id}")->assertOk();
        }
    }

    private function say(User $user, string $text): int
    {
        return $this->actingAs($user)->postJson("/conversations/{$this->group->id}/messages", ['message' => $text])->assertCreated()->json('id');
    }
}

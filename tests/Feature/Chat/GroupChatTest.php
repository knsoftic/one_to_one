<?php

namespace Tests\Feature\Chat;

use App\Events\GroupUpdated;
use App\Events\MessageSent;
use App\Events\MessagesStatusUpdated;
use App\Events\UserTyping;
use App\Models\ChatSetting;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\DeviceToken;
use App\Models\Message;
use App\Models\User;
use App\Services\DeviceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ConfiguresFirebase;
use Tests\TestCase;

/**
 * G1 — Creating a group chat and talking in it.
 */
class GroupChatTest extends TestCase
{
    use ConfiguresFirebase, RefreshDatabase;

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

    public function test_a_group_is_created_with_a_name_icon_description_and_people(): void
    {
        Storage::fake('public');
        Event::fake([MessageSent::class, GroupUpdated::class]);

        $group = $this->actingAs($this->ayesha)->post('/groups', [
            'name' => '  Family   Group ',
            'description' => 'Weekend plans',
            'member_ids' => [$this->bilal->id, $this->sara->id],
            'avatar' => UploadedFile::fake()->image('family.jpg', 400, 400),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('type', 'group')
            ->assertJsonPath('participant', null)
            ->assertJsonPath('group.name', 'Family Group')
            ->assertJsonPath('group.description', 'Weekend plans')
            ->assertJsonPath('group.initials', 'FG')
            ->assertJsonPath('group.member_count', 3)
            ->assertJsonPath('group.my_role', 'admin')
            ->assertJsonPath('group.can_send', true)
            ->assertJsonCount(3, 'group.members')
            ->assertJsonPath('group.members.0.user.id', $this->ayesha->id)
            ->assertJsonPath('group.members.0.is_creator', true)
            ->assertJsonPath('last_message.type', 'system')
            ->assertJsonPath('last_message.preview', 'Ayesha created group "Family Group"')
            ->json();

        $this->assertNotNull($group['group']['avatar_url']);
        Storage::disk('public')->assertExists(Conversation::find($group['id'])->avatar);

        // Everyone in the group gets the notice; the added people are notified.
        Event::assertDispatched(MessageSent::class, fn (MessageSent $event) => collect($event->broadcastOn())->map->name->sort()->values()->all()
            === collect([$this->ayesha, $this->bilal, $this->sara])->map(fn ($u) => 'private-App.Models.User.'.$u->id)->sort()->values()->all());

        $this->actingAs($this->bilal)->getJson('/conversations')
            ->assertJsonPath('0.id', $group['id'])
            ->assertJsonPath('0.group.my_role', 'member')
            ->assertJsonPath('0.group.member_count', 3)
            ->assertJsonPath('0.unread_count', 0);
    }

    public function test_creating_a_group_needs_a_name_and_people(): void
    {
        $this->actingAs($this->ayesha)->postJson('/groups', ['name' => 'Empty', 'member_ids' => []])->assertJsonValidationErrors('member_ids');
        $this->actingAs($this->ayesha)->postJson('/groups', ['name' => '', 'member_ids' => [$this->bilal->id]])->assertJsonValidationErrors('name');
        $this->actingAs($this->ayesha)->postJson('/groups', ['name' => 'Me', 'member_ids' => [$this->ayesha->id]])
            ->assertUnprocessable()->assertJsonPath('message', 'Add at least one person to the group.');

        config(['chat.groups.max_members' => 3]);
        $this->actingAs($this->ayesha)->postJson('/groups', ['name' => 'Big', 'member_ids' => User::factory()->count(3)->create()->modelKeys()])
            ->assertJsonValidationErrors('member_ids');
    }

    public function test_messages_reach_everyone_in_the_group_with_unread_counts_and_read_ticks(): void
    {
        $group = $this->group();

        $sent = $this->actingAs($this->ayesha)->postJson("/conversations/{$group->id}/messages", ['message' => 'Dinner at 8?'])
            ->assertCreated()
            ->assertJsonPath('receiver_id', null)
            ->assertJsonPath('status', 'sent')
            ->json('id');

        foreach ([$this->bilal, $this->sara] as $member) {
            $this->actingAs($member)->getJson("/conversations/{$group->id}/messages")
                ->assertJsonPath('data.1.id', $sent)
                ->assertJsonPath('data.1.sender_name', 'Ayesha');
            $this->actingAs($member)->getJson('/conversations')->assertJsonPath('0.unread_count', 1)
                ->assertJsonPath('0.last_message.sender_name', 'Ayesha');
        }

        // Ticks: delivered once both phones received it, read once both opened the group.
        Event::fake([MessagesStatusUpdated::class]);
        $this->actingAs($this->bilal)->postJson('/messages/delivered', ['ids' => [$sent]])->assertOk();
        $this->assertSame('sent', Message::find($sent)->status());
        $this->actingAs($this->sara)->postJson('/messages/delivered')->assertOk();
        $this->assertSame('delivered', Message::find($sent)->status());

        $this->actingAs($this->bilal)->postJson("/conversations/{$group->id}/seen")->assertOk();
        $this->actingAs($this->bilal)->getJson('/conversations')->assertJsonPath('0.unread_count', 0);
        $this->assertSame('delivered', Message::find($sent)->status());

        $this->actingAs($this->sara)->postJson("/conversations/{$group->id}/seen")->assertOk();
        $this->assertSame('seen', Message::find($sent)->status());

        Event::assertDispatched(MessagesStatusUpdated::class, fn ($e) => $e->status === 'delivered' && $e->senderId === $this->ayesha->id && $e->messageIds === [$sent]);
        Event::assertDispatched(MessagesStatusUpdated::class, fn ($e) => $e->status === 'seen' && $e->messageIds === [$sent]);
    }

    public function test_people_added_later_see_messages_from_when_they_joined(): void
    {
        $group = $this->group();
        $this->actingAs($this->ayesha)->postJson("/conversations/{$group->id}/messages", ['message' => 'Before Hina'])->assertCreated();

        $hina = User::factory()->create(['name' => 'Hina']);
        $this->actingAs($this->bilal)->postJson("/groups/{$group->id}/members", ['user_ids' => [$hina->id, $this->sara->id]])
            ->assertOk()
            ->assertJsonPath('group.member_count', 4)
            ->assertJsonPath('last_message.preview', 'Bilal added Hina');

        $this->actingAs($this->ayesha)->postJson("/conversations/{$group->id}/messages", ['message' => 'Welcome Hina'])->assertCreated();

        $texts = collect($this->actingAs($hina)->getJson("/conversations/{$group->id}/messages")->json('data'))
            ->map(fn ($m) => $m['body'] ?? $m['system']['text'])->all();
        $this->assertSame(['Bilal added Hina', 'Welcome Hina'], $texts);
        $this->actingAs($hina)->getJson('/conversations')->assertJsonPath('0.unread_count', 1);

        // Old messages cannot be opened by id either.
        $old = Message::query()->where('message', 'Before Hina')->sole();
        $this->actingAs($hina)->putJson("/messages/{$old->id}/star")->assertNotFound();
    }

    public function test_strangers_cannot_see_or_write_to_a_group(): void
    {
        $group = $this->group();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->getJson("/conversations/{$group->id}")->assertNotFound();
        $this->actingAs($stranger)->getJson("/conversations/{$group->id}/messages")->assertNotFound();
        $this->actingAs($stranger)->postJson("/conversations/{$group->id}/messages", ['message' => 'hi'])->assertNotFound();
        $this->actingAs($stranger)->postJson("/groups/{$group->id}/members", ['user_ids' => [$stranger->id]])->assertNotFound();
        $this->actingAs($stranger)->getJson('/conversations')->assertJsonCount(0);

        // A group cannot be called like a person.
        $this->actingAs($this->ayesha)->postJson("/conversations/{$group->id}/calls", ['type' => 'audio', 'client_id' => 'caller-tab-01'])
            ->assertUnprocessable();
    }

    public function test_group_info_changes_leave_notices(): void
    {
        Storage::fake('public');
        $group = $this->group();

        $this->actingAs($this->sara)->post("/groups/{$group->id}", [
            'name' => 'Cousins',
            'description' => 'Only cousins',
            'avatar' => UploadedFile::fake()->image('icon.png', 300, 300),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('group.name', 'Cousins')
            ->assertJsonPath('group.description', 'Only cousins');

        $this->actingAs($this->sara)->post("/groups/{$group->id}", ['remove_avatar' => true], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('group.avatar_url', null);

        $notices = Message::query()->where('conversation_id', $group->id)->where('message_type', 'system')->orderBy('id')->get()->map->systemText()->all();
        $this->assertSame([
            'Ayesha created group "Family"',
            'Sara changed the group name to "Cousins"',
            'Sara changed the group description',
            "Sara changed this group's icon",
            "Sara deleted this group's icon",
        ], $notices);
    }

    public function test_delete_for_me_typing_and_mute_in_groups(): void
    {
        $group = $this->group();
        $message = $this->actingAs($this->ayesha)->postJson("/conversations/{$group->id}/messages", ['message' => 'Secret plan'])->json('id');

        // Bilal hides it for himself only.
        $this->actingAs($this->bilal)->deleteJson("/messages/{$message}", ['scope' => 'me'])->assertOk();
        $this->actingAs($this->bilal)->getJson("/conversations/{$group->id}/messages")->assertJsonCount(1, 'data');
        $this->actingAs($this->sara)->getJson("/conversations/{$group->id}/messages")->assertJsonCount(2, 'data');
        $this->actingAs($this->bilal)->getJson('/conversations')->assertJsonPath('0.unread_count', 0);

        // Typing reaches everyone else.
        Event::fake([UserTyping::class]);
        $this->actingAs($this->sara)->postJson("/conversations/{$group->id}/typing", ['typing' => true])->assertSuccessful();
        Event::assertDispatched(UserTyping::class, fn (UserTyping $e) => count($e->broadcastOn()) === 2 && $e->userId === $this->sara->id);

        // Muted members get no notification; the others do.
        ChatSetting::create(['conversation_id' => $group->id, 'user_id' => $this->sara->id, 'muted_until' => ChatSetting::MUTE_ALWAYS_UNTIL]);
        $this->actingAs($this->ayesha)->postJson("/conversations/{$group->id}/messages", ['message' => 'Everyone?'])->assertCreated();
        $this->assertSame('Ayesha in Family', $this->bilal->notifications()->where('data->body', 'Everyone?')->sole()->data['title']);
        $this->assertSame(0, $this->sara->notifications()->where('data->body', 'Everyone?')->count());
    }

    public function test_phones_show_the_group_with_who_wrote_the_message(): void
    {
        $this->configureFirebase();
        $this->fakeFirebase();
        $group = $this->group();
        $device = app(DeviceService::class)->issue($this->bilal, 'android')['device'];
        $device->forceFill(['fcm_token' => 'fcm-token-bilal-123456', 'fcm_token_hash' => DeviceToken::hashToken('fcm-token-bilal-123456')])->save();
        Contact::create(['user_id' => $this->bilal->id, 'contact_user_id' => $this->ayesha->id, 'name' => 'Ayesha Baji', 'phone' => '03001234567']);

        $this->actingAs($this->ayesha)->postJson("/conversations/{$group->id}/messages", ['message' => 'Kal milte hain'])->assertCreated();

        Http::assertSent(function (Request $request) use ($group) {
            if (! str_contains($request->url(), 'messages:send')) {
                return false;
            }
            $data = $request['message']['data'];

            return $data['conversation_id'] === (string) $group->id
                && $data['sender_name'] === 'Family'
                && $data['body'] === 'Ayesha Baji: Kal milte hain'
                && $data['initials'] === 'F'
                && $data['sender_id'] === (string) -$group->id;
        });
    }

    private function group(): Conversation
    {
        $id = $this->actingAs($this->ayesha)->postJson('/groups', [
            'name' => 'Family',
            'member_ids' => [$this->bilal->id, $this->sara->id],
        ])->assertCreated()->json('id');

        return Conversation::findOrFail($id);
    }
}

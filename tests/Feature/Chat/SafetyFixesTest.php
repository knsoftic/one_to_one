<?php

namespace Tests\Feature\Chat;

use App\Events\MessageUpdated;
use App\Models\CallRoomParticipant;
use App\Models\ChatSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\NewMessageNotification;
use App\Services\CallRoomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fixes from the feature check: privacy and safety holes in existing features.
 */
class SafetyFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $ayesha;

    private User $bilal;

    private User $sara;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->ayesha, $this->bilal, $this->sara] = [
            User::factory()->create(['name' => 'Ayesha', 'email' => 'ayesha.k@example.com', 'phone' => '+923451234567']),
            User::factory()->create(['name' => 'Bilal']),
            User::factory()->create(['name' => 'Sara']),
        ];
    }

    public function test_people_who_join_a_group_later_cant_reach_older_messages(): void
    {
        $group = $this->actingAs($this->ayesha)->postJson('/groups', ['name' => 'Family', 'member_ids' => [$this->bilal->id]])->json('id');
        $old = $this->actingAs($this->ayesha)->postJson("/conversations/{$group}/messages", ['message' => 'Old family secret'])->json('id');
        $this->actingAs($this->ayesha)->postJson("/groups/{$group}/members", ['user_ids' => [$this->sara->id]])->assertOk();

        // Quoting it to read a preview is refused; members who saw it can reply.
        $this->actingAs($this->sara)->postJson("/conversations/{$group}/messages", ['message' => 'hi', 'reply_to_id' => $old])->assertJsonValidationErrors('reply_to_id');
        $this->actingAs($this->bilal)->postJson("/conversations/{$group}/messages", ['message' => 'yes', 'reply_to_id' => $old])->assertCreated();

        // Changes to it only go to people who could see it.
        $this->assertEqualsCanonicalizing([$this->ayesha->id, $this->bilal->id], Message::findOrFail($old)->audienceIds());
    }

    public function test_search_needs_the_full_email_or_mobile_number(): void
    {
        $me = User::factory()->create();

        foreach (['Ayesha', 'ayesha.k@example.com', '0345 1234567', '+92 345 1234567'] as $term) {
            $this->actingAs($me)->getJson('/users/search?q='.urlencode($term))->assertJsonFragment(['id' => $this->ayesha->id]);
        }
        foreach (['ayesha.k@exam', '0345 1234', '451234'] as $term) {
            $this->actingAs($me)->getJson('/users/search?q='.urlencode($term))->assertJsonMissing(['id' => $this->ayesha->id]);
        }

        // Admins can still search by parts.
        $this->actingAs(User::factory()->admin()->create())->get('/admin/users?q=ayesha.k@exam')->assertSee('ayesha.k@example.com');
    }

    public function test_a_locked_chat_cant_be_unlocked_or_read_from_notifications_without_the_code(): void
    {
        $chat = Conversation::factory()->between($this->ayesha, $this->bilal)->create();
        $this->actingAs($this->ayesha)->postJson('/chat-lock/pin', ['pin' => '1234', 'pin_confirmation' => '1234'])->assertOk();
        $this->actingAs($this->ayesha)->patchJson("/conversations/{$chat->id}/settings", ['locked' => true])->assertOk();
        $this->actingAs($this->ayesha)->postJson('/chat-lock/lock')->assertOk();

        // Taking it out of "Locked chats" needs the code.
        $this->actingAs($this->ayesha)->patchJson("/conversations/{$chat->id}/settings", ['locked' => false])->assertStatus(423);
        $this->assertTrue(ChatSetting::query()->where('conversation_id', $chat->id)->whereNotNull('locked_at')->exists());

        // A notification stored before hides who wrote and what.
        $message = Message::factory()->inConversation($chat, $this->bilal)->create(['message' => 'Private plan']);
        $this->ayesha->notify(new NewMessageNotification($message->load('sender')));
        $bell = $this->actingAs($this->ayesha)->getJson('/notifications')->assertOk();
        $this->assertStringNotContainsString('Private plan', $bell->getContent());
        $this->assertStringNotContainsString('Bilal', $bell->getContent());
        $bell->assertJsonPath('data.0.data.locked', true);

        $this->actingAs($this->ayesha)->postJson('/chat-lock/unlock', ['pin' => '1234'])->assertOk();
        $this->actingAs($this->ayesha)->getJson('/notifications')->assertSee('Bilal');
        $this->actingAs($this->ayesha)->patchJson("/conversations/{$chat->id}/settings", ['locked' => false])->assertOk();
    }

    public function test_channel_followers_dont_see_which_admin_posted(): void
    {
        $channel = $this->actingAs($this->ayesha)->postJson('/channels', ['name' => 'News'])->json('id');
        $this->actingAs($this->bilal)->postJson("/channels/{$channel}/follow")->assertOk();
        $this->actingAs($this->ayesha)->postJson("/conversations/{$channel}/messages", ['message' => 'Breaking'])->assertCreated()->assertJsonPath('sender_id', $this->ayesha->id);

        $this->actingAs($this->bilal)->getJson("/conversations/{$channel}/messages")->assertJsonPath('data.0.sender_id', null)->assertJsonPath('data.0.is_mine', false);
        $this->actingAs($this->ayesha)->getJson("/conversations/{$channel}/messages")->assertJsonPath('data.0.sender_id', $this->ayesha->id);
    }

    public function test_view_once_is_only_for_one_to_one_chats(): void
    {
        Storage::fake('chat');
        $group = $this->actingAs($this->ayesha)->postJson('/groups', ['name' => 'Friends', 'member_ids' => [$this->bilal->id, $this->sara->id]])->json('id');

        $sent = $this->actingAs($this->ayesha)->post("/conversations/{$group}/messages", ['attachment' => UploadedFile::fake()->image('p.jpg', 200, 200), 'view_once' => '1'], ['Accept' => 'application/json'])->assertCreated();
        $this->assertArrayNotHasKey('view_once', Message::findOrFail($sent->json('id'))->attachment_meta ?? []);
    }

    public function test_people_no_longer_in_a_group_dont_see_its_members(): void
    {
        $group = $this->actingAs($this->ayesha)->postJson('/groups', ['name' => 'Team', 'member_ids' => [$this->bilal->id, $this->sara->id]])->json('id');
        $this->actingAs($this->ayesha)->deleteJson("/groups/{$group}/members/{$this->bilal->id}")->assertOk();

        $members = $this->actingAs($this->bilal)->getJson("/conversations/{$group}")->assertOk()->json('group.members');
        $this->assertSame([$this->bilal->id], array_column(array_column($members, 'user'), 'id'));
        $this->assertCount(3, $this->actingAs($this->sara)->getJson("/conversations/{$group}")->json('group.members'));
    }

    public function test_read_receipts_off_hide_blue_ticks_of_broadcast_lists(): void
    {
        $list = $this->actingAs($this->ayesha)->postJson('/broadcasts', ['user_ids' => [$this->bilal->id, $this->sara->id]])->json('id');
        $original = $this->actingAs($this->ayesha)->postJson("/conversations/{$list}/messages", ['message' => 'Eid Mubarak'])->json('id');
        $this->sara->forceFill(['read_receipts' => false])->save();

        foreach ([$this->bilal, $this->sara] as $person) {
            $chat = Conversation::query()->between($this->ayesha, $person)->firstOrFail();
            $this->actingAs($person)->postJson("/conversations/{$chat->id}/seen")->assertOk();
        }

        $this->assertNotSame('seen', Message::findOrFail($original)->status());
        $receipts = collect($this->actingAs($this->ayesha)->getJson("/messages/{$original}/receipts")->json('data'))->keyBy('user.id');
        $this->assertNotNull($receipts[$this->bilal->id]['seen_at']);
        $this->assertNull($receipts[$this->sara->id]['seen_at']);
    }

    public function test_forwarded_media_keeps_no_mentions_and_stale_group_calls_free_people(): void
    {
        Storage::fake('chat');
        $group = $this->actingAs($this->ayesha)->postJson('/groups', ['name' => 'Club', 'member_ids' => [$this->bilal->id]])->json('id');
        $photo = $this->actingAs($this->ayesha)->post("/conversations/{$group}/messages", [
            'attachment' => UploadedFile::fake()->image('p.jpg', 200, 200), 'message' => 'Look @Bilal', 'mentions' => [['id' => $this->bilal->id, 'name' => 'Bilal']],
        ], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $this->assertSame([$this->bilal->id], Message::findOrFail($photo)->attachment_meta['mention_ids']);

        $other = $this->actingAs($this->ayesha)->postJson('/groups', ['name' => 'Other', 'member_ids' => [$this->bilal->id]])->json('id');
        $this->actingAs($this->ayesha)->postJson("/messages/{$photo}/forward", ['conversation_ids' => [$other]])->assertSuccessful();
        $copy = Message::query()->where('conversation_id', $other)->where('message_type', 'image')->sole();
        $this->assertArrayNotHasKey('mention_ids', $copy->attachment_meta ?? []);

        // A group call whose device vanished doesn't keep the person busy.
        Event::fake([MessageUpdated::class]);
        $room = $this->actingAs($this->ayesha)->postJson('/call-rooms', ['user_ids' => [$this->bilal->id], 'type' => 'audio', 'client_id' => 'ayesha-tab-01'])->assertCreated()->json('room.id');
        $rooms = app(CallRoomService::class);
        $this->assertTrue($rooms->isBusy($this->ayesha));
        $this->travel(5)->minutes();
        $this->artisan('chat:expire-calls')->assertSuccessful();
        $this->assertFalse($rooms->isBusy($this->ayesha));
        $this->assertFalse(CallRoomParticipant::query()->where('call_room_id', $room)->where('user_id', $this->ayesha->id)->joined()->exists());
    }
}

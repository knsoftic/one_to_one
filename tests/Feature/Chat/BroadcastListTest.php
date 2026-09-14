<?php

namespace Tests\Feature\Chat;

use App\Events\MessageSent;
use App\Models\BlockedUser;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * G9 — Broadcast lists.
 */
class BroadcastListTest extends TestCase
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

    public function test_a_broadcast_list_is_created_renamed_and_its_recipients_changed(): void
    {
        $this->actingAs($this->ayesha)->postJson('/broadcasts', ['user_ids' => [$this->bilal->id]])
            ->assertJsonValidationErrors(['user_ids' => 'A broadcast list needs at least 2 people.']);

        $list = $this->actingAs($this->ayesha)->postJson('/broadcasts', ['user_ids' => [$this->bilal->id, $this->sara->id]])
            ->assertCreated()
            ->assertJsonPath('type', 'broadcast')
            ->assertJsonPath('broadcast.name', null)
            ->assertJsonPath('broadcast.recipient_count', 2)
            ->assertJsonPath('broadcast.is_owner', true)
            ->assertJsonCount(2, 'broadcast.recipients')
            ->json('id');

        $hina = User::factory()->create();
        $this->actingAs($this->ayesha)->patchJson("/broadcasts/{$list}", ['name' => 'Customers', 'user_ids' => [$this->sara->id, $hina->id]])
            ->assertOk()
            ->assertJsonPath('broadcast.name', 'Customers')
            ->assertJsonPath('broadcast.recipient_count', 2);

        // Only the owner sees or changes a list; recipients never know about it.
        $this->actingAs($this->sara)->getJson("/conversations/{$list}")->assertNotFound();
        $this->actingAs($this->sara)->getJson('/conversations')->assertJsonCount(0);
        $this->actingAs($this->sara)->patchJson("/broadcasts/{$list}", ['name' => 'Mine'])->assertNotFound();
        $this->actingAs($this->sara)->postJson("/conversations/{$list}/messages", ['message' => 'hi'])->assertNotFound();

        $this->actingAs($this->ayesha)->deleteJson("/broadcasts/{$list}")->assertOk();
        $this->assertNull(Conversation::find($list));
    }

    public function test_messages_reach_each_recipient_in_their_own_chat(): void
    {
        Storage::fake('chat');
        $blocker = User::factory()->create();
        BlockedUser::create(['user_id' => $blocker->id, 'blocked_user_id' => $this->ayesha->id]);
        $list = $this->actingAs($this->ayesha)->postJson('/broadcasts', ['user_ids' => [$this->bilal->id, $this->sara->id, User::factory()->create()->id]])->json('id');
        // Someone who blocks Ayesha after being added gets nothing.
        DB::table('broadcast_recipients')->insert(['conversation_id' => $list, 'user_id' => $blocker->id, 'created_at' => now()]);

        $original = $this->actingAs($this->ayesha)->postJson("/conversations/{$list}/messages", ['message' => 'Eid Mubarak! 🌙'])
            ->assertCreated()
            ->assertJsonPath('receiver_id', null)
            ->json('id');

        $copies = Message::query()->where('broadcast_message_id', $original)->get();
        $this->assertCount(3, $copies);
        $this->assertFalse($copies->pluck('receiver_id')->contains($blocker->id));

        // Bilal gets it in his chat with Ayesha, and replies there.
        $chat = Conversation::query()->between($this->ayesha, $this->bilal)->sole();
        $this->actingAs($this->bilal)->getJson('/conversations')
            ->assertJsonPath('0.id', $chat->id)
            ->assertJsonPath('0.type', 'direct')
            ->assertJsonPath('0.last_message.preview', 'Eid Mubarak! 🌙')
            ->assertJsonPath('0.unread_count', 1);

        // Photos are copied too.
        $this->actingAs($this->ayesha)->post("/conversations/{$list}/messages", ['attachment' => UploadedFile::fake()->image('eid.jpg', 400, 300)], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame(3, Message::query()->where('message_type', 'image')->whereNotNull('broadcast_message_id')->count());
        $this->assertSame(3, Message::query()->where('message_type', 'image')->whereNotNull('broadcast_message_id')->distinct()->count('attachment'));
    }

    public function test_the_list_shows_ticks_and_read_by_from_the_copies_and_deletes_them_for_everyone(): void
    {
        $list = $this->actingAs($this->ayesha)->postJson('/broadcasts', ['user_ids' => [$this->bilal->id, $this->sara->id]])->json('id');
        $original = $this->actingAs($this->ayesha)->postJson("/conversations/{$list}/messages", ['message' => 'Sale starts today'])->json('id');

        $bilalChat = Conversation::query()->between($this->ayesha, $this->bilal)->sole();
        $saraChat = Conversation::query()->between($this->ayesha, $this->sara)->sole();

        $this->actingAs($this->bilal)->postJson("/conversations/{$bilalChat->id}/seen")->assertOk();
        $this->assertSame('sent', Message::find($original)->status());
        $this->actingAs($this->sara)->postJson('/messages/delivered')->assertOk();
        $this->assertSame('delivered', Message::find($original)->status());
        $this->actingAs($this->sara)->postJson("/conversations/{$saraChat->id}/seen")->assertOk();
        $this->assertSame('seen', Message::find($original)->status());

        $receipts = collect($this->actingAs($this->ayesha)->getJson("/messages/{$original}/receipts")->assertOk()->json('data'))->keyBy('user.id');
        $this->assertCount(2, $receipts);
        $this->assertNotNull($receipts[$this->bilal->id]['seen_at']);

        // No typing, calls, reactions or edits in a list.
        Event::fake([MessageSent::class]);
        $this->actingAs($this->ayesha)->postJson("/conversations/{$list}/calls", ['type' => 'audio', 'client_id' => 'caller-tab-01'])->assertUnprocessable();
        $this->actingAs($this->ayesha)->putJson("/messages/{$original}/reaction", ['emoji' => '👍'])->assertForbidden();
        $this->actingAs($this->ayesha)->patchJson("/messages/{$original}", ['message' => 'Sale starts tomorrow'])->assertForbidden();

        $this->actingAs($this->ayesha)->deleteJson("/messages/{$original}", ['scope' => 'everyone'])->assertOk();
        $this->assertSame(3, Message::query()->where('deleted_for_everyone', true)->count());
        $this->actingAs($this->bilal)->getJson("/conversations/{$bilalChat->id}/messages")->assertJsonPath('data.0.is_deleted', true);
    }
}

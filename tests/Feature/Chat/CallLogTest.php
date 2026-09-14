<?php

namespace Tests\Feature\Chat;

use App\Models\Call;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\CallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * K1 — Calls tab: call history with missed calls, call back, removing and clearing.
 */
class CallLogTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $friend;

    private Conversation $chat;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->me, $this->friend] = User::factory()->count(2)->create();
        $this->chat = Conversation::factory()->between($this->me, $this->friend)->create();
    }

    public function test_the_call_log_lists_my_calls_newest_first_with_direction_and_missed_calls(): void
    {
        $outgoing = $this->endedCall($this->me, $this->friend, 'video', Call::REASON_COMPLETED, answered: true);
        $missed = $this->endedCall($this->friend, $this->me, 'audio', Call::REASON_MISSED);
        Contact::create(['user_id' => $this->me->id, 'contact_user_id' => $this->friend->id, 'name' => 'Ali Bhai', 'phone' => '03001234567']);

        // Calls of other people and calls still ringing are not listed.
        $stranger = User::factory()->create();
        $this->endedCall($stranger, $this->friend, 'audio', Call::REASON_COMPLETED, conversation: Conversation::factory()->between($stranger, $this->friend)->create());
        Call::create(['conversation_id' => $this->chat->id, 'caller_id' => $this->friend->id, 'callee_id' => $this->me->id, 'type' => 'audio', 'status' => Call::STATUS_RINGING, 'caller_client' => 'friend-tab-01']);

        $this->actingAs($this->me)->getJson('/calls')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('unseen_missed', 1)
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('data.0.id', $missed->id)
            ->assertJsonPath('data.0.direction', 'incoming')
            ->assertJsonPath('data.0.missed', true)
            ->assertJsonPath('data.0.peer.id', $this->friend->id)
            ->assertJsonPath('data.0.peer.saved_name', 'Ali Bhai')
            ->assertJsonPath('data.1.id', $outgoing->id)
            ->assertJsonPath('data.1.direction', 'outgoing')
            ->assertJsonPath('data.1.type', 'video')
            ->assertJsonPath('data.1.missed', false)
            ->assertJsonPath('data.1.conversation_id', $this->chat->id);

        // The friend sees the same missed call as an outgoing call nobody answered.
        $friendsView = collect($this->actingAs($this->friend)->getJson('/calls')->json('data'))->firstWhere('id', $missed->id);
        $this->assertSame(['outgoing', false, 'missed'], [$friendsView['direction'], $friendsView['missed'], $friendsView['end_reason']]);
    }

    public function test_opening_the_calls_tab_marks_missed_calls_as_seen(): void
    {
        $this->endedCall($this->friend, $this->me, 'audio', Call::REASON_MISSED);
        $this->endedCall($this->friend, $this->me, 'audio', Call::REASON_CANCELLED);

        $this->actingAs($this->me)->getJson('/conversations')->assertJsonPath('0.unread_count', 2);

        $this->actingAs($this->me)->postJson('/calls/seen')->assertOk()->assertJsonPath('updated', 2);

        $this->actingAs($this->me)->getJson('/calls')->assertJsonPath('unseen_missed', 0);
        $this->actingAs($this->me)->getJson('/conversations')->assertJsonPath('0.unread_count', 0);
    }

    public function test_calls_are_removed_from_my_log_only(): void
    {
        $first = $this->endedCall($this->me, $this->friend, 'audio', Call::REASON_COMPLETED, answered: true);
        $second = $this->endedCall($this->friend, $this->me, 'video', Call::REASON_DECLINED);
        $third = $this->endedCall($this->me, $this->friend, 'audio', Call::REASON_BUSY);

        $this->actingAs($this->me)->deleteJson("/calls/{$first->id}")->assertOk();
        $this->actingAs($this->me)->deleteJson("/calls/{$first->id}")->assertNotFound();
        $this->actingAs(User::factory()->create())->deleteJson("/calls/{$second->id}")->assertNotFound();

        $this->actingAs($this->me)->getJson('/calls')->assertJsonCount(2, 'data');
        // The history bubble in the chat is gone for me too.
        $this->assertTrue($first->message->fresh()->deleted_for_sender);

        $this->actingAs($this->me)->deleteJson('/calls')->assertOk()->assertJsonPath('removed', 2);
        $this->actingAs($this->me)->getJson('/calls')->assertJsonCount(0, 'data');
        $this->actingAs($this->me)->getJson("/conversations/{$this->chat->id}/messages")->assertJsonCount(0, 'data');

        // The other person keeps their call log.
        $this->actingAs($this->friend)->getJson('/calls')->assertJsonCount(3, 'data');
        $this->assertSame(3, Message::query()->where('message_type', Message::TYPE_CALL)->count());
        $this->assertSame($third->id, $this->actingAs($this->friend)->getJson('/calls')->json('data.0.id'));
    }

    public function test_the_call_log_respects_cleared_and_locked_chats_and_pages(): void
    {
        foreach (range(1, 31) as $i) {
            $this->endedCall($this->me, $this->friend, 'audio', Call::REASON_COMPLETED, answered: true);
        }

        $page = $this->actingAs($this->me)->getJson('/calls')->assertJsonCount(30, 'data')->assertJsonPath('has_more', true);
        $this->actingAs($this->me)->getJson('/calls?before='.$page->json('data.29.id'))->assertJsonCount(1, 'data')->assertJsonPath('has_more', false);

        // Locked chats (C9) keep their calls out of the log until the code is entered.
        $this->actingAs($this->me)->postJson('/chat-lock/pin', ['pin' => '2580', 'pin_confirmation' => '2580'])->assertOk();
        $this->actingAs($this->me)->patchJson("/conversations/{$this->chat->id}/settings", ['locked' => true])->assertOk();
        $this->actingAs($this->me)->postJson('/chat-lock/lock')->assertOk();
        $this->actingAs($this->me)->getJson('/calls')->assertJsonCount(0, 'data');
        $this->actingAs($this->me)->postJson('/chat-lock/unlock', ['pin' => '2580'])->assertOk();

        // Clear chat (C5) clears its calls from the log as well.
        $this->actingAs($this->me)->postJson("/conversations/{$this->chat->id}/clear")->assertOk();
        $this->actingAs($this->me)->getJson('/calls')->assertJsonCount(0, 'data');
    }

    private function endedCall(User $caller, User $callee, string $type, string $reason, bool $answered = false, ?Conversation $conversation = null): Call
    {
        $call = Call::create([
            'conversation_id' => ($conversation ?? $this->chat)->id,
            'caller_id' => $caller->id,
            'callee_id' => $callee->id,
            'type' => $type,
            'status' => $answered ? Call::STATUS_ONGOING : Call::STATUS_RINGING,
            'caller_client' => 'caller-tab-01',
            'answered_at' => $answered ? now()->subMinute() : null,
        ]);

        return app(CallService::class)->finish($call, $reason, $caller);
    }
}

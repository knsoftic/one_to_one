<?php

namespace Tests\Feature\Privacy;

use App\Events\MessagesStatusUpdated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * P3 — Read receipts off.
 */
class ReadReceiptsTest extends TestCase
{
    use RefreshDatabase;

    public function test_nobody_sees_blue_ticks_when_either_person_turned_them_off(): void
    {
        $ayesha = User::factory()->create();
        $bilal = User::factory()->create(['read_receipts' => false]);
        $chat = Conversation::factory()->between($ayesha, $bilal)->create();

        $id = $this->actingAs($ayesha)->postJson("/conversations/{$chat->id}/messages", ['message' => 'Kal milte hain'])->json('id');

        Event::fake([MessagesStatusUpdated::class]);
        $this->actingAs($bilal)->postJson("/conversations/{$chat->id}/seen")->assertOk();
        Event::assertNotDispatched(MessagesStatusUpdated::class, fn (MessagesStatusUpdated $e) => $e->status === Message::STATUS_SEEN);

        // Read on the server (no unread for Bilal), but Ayesha sees "delivered".
        $this->assertNotNull(Message::find($id)->seen_at);
        $this->actingAs($bilal)->getJson('/conversations')->assertJsonPath('0.unread_count', 0);
        $this->actingAs($ayesha)->getJson("/conversations/{$chat->id}/messages")
            ->assertJsonPath('data.0.status', 'delivered')
            ->assertJsonPath('data.0.seen_at', null);
        $this->actingAs($ayesha)->getJson('/conversations')->assertJsonPath('0.last_message.status', 'delivered');

        // Turning them off also hides other people's receipts from you.
        $bilal->forceFill(['read_receipts' => true])->save();
        $ayesha->forceFill(['read_receipts' => false])->save();
        $this->actingAs($ayesha)->getJson("/conversations/{$chat->id}/messages")->assertJsonPath('data.0.status', 'delivered');

        $this->actingAs($ayesha)->patchJson('/settings/preferences', ['read_receipts' => true])->assertJsonPath('preferences.read_receipts', true);
        $this->actingAs($ayesha)->getJson("/conversations/{$chat->id}/messages")->assertJsonPath('data.0.status', 'seen');
    }

    public function test_groups_keep_their_receipts(): void
    {
        $ayesha = User::factory()->create();
        $bilal = User::factory()->create(['read_receipts' => false]);
        $group = $this->actingAs($ayesha)->postJson('/groups', ['name' => 'Family', 'member_ids' => [$bilal->id]])->json('id');
        $id = $this->actingAs($ayesha)->postJson("/conversations/{$group}/messages", ['message' => 'Dinner at 8'])->json('id');

        $this->actingAs($bilal)->postJson("/conversations/{$group}/seen")->assertOk();

        $this->assertSame('seen', collect($this->actingAs($ayesha)->getJson("/conversations/{$group}/messages")->json('data'))->firstWhere('id', $id)['status']);
    }
}

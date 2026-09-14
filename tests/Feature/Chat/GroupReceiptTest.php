<?php

namespace Tests\Feature\Chat;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * G6 — "Read by" and "Delivered to" of a group message.
 */
class GroupReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sender_sees_who_read_and_who_received_a_group_message(): void
    {
        [$ayesha, $bilal, $sara, $hina] = [
            User::factory()->create(['name' => 'Ayesha']),
            User::factory()->create(['name' => 'Bilal']),
            User::factory()->create(['name' => 'Sara']),
            User::factory()->create(['name' => 'Hina']),
        ];
        $group = Conversation::findOrFail($this->actingAs($ayesha)->postJson('/groups', ['name' => 'Family', 'member_ids' => [$bilal->id, $sara->id, $hina->id]])->json('id'));
        Contact::create(['user_id' => $ayesha->id, 'contact_user_id' => $bilal->id, 'name' => 'Bilal Bhai', 'phone' => '03001234567']);

        $message = $this->actingAs($ayesha)->postJson("/conversations/{$group->id}/messages", ['message' => 'Dinner at 8?'])->json('id');

        $this->actingAs($bilal)->postJson("/conversations/{$group->id}/seen")->assertOk();
        $this->actingAs($sara)->postJson('/messages/delivered', ['ids' => [$message]])->assertOk();

        $receipts = collect($this->actingAs($ayesha)->getJson("/messages/{$message}/receipts")->assertOk()->json('data'))->keyBy('user.id');

        $this->assertCount(3, $receipts);
        $this->assertSame('Bilal Bhai', $receipts[$bilal->id]['user']['saved_name']);
        $this->assertNotNull($receipts[$bilal->id]['seen_at']);
        $this->assertNotNull($receipts[$bilal->id]['delivered_at']);
        $this->assertNull($receipts[$sara->id]['seen_at']);
        $this->assertNotNull($receipts[$sara->id]['delivered_at']);
        $this->assertNull($receipts[$hina->id]['delivered_at']);

        // Only the sender can see this, and only for group messages.
        $this->actingAs($bilal)->getJson("/messages/{$message}/receipts")->assertNotFound();
        $this->actingAs(User::factory()->create())->getJson("/messages/{$message}/receipts")->assertNotFound();
        $direct = Conversation::factory()->between($ayesha, $bilal)->create();
        $directMessage = $this->actingAs($ayesha)->postJson("/conversations/{$direct->id}/messages", ['message' => 'hi'])->json('id');
        $this->actingAs($ayesha)->getJson("/messages/{$directMessage}/receipts")->assertNotFound();

        // Someone who left without receiving it no longer counts: the message is delivered to everyone else.
        $this->actingAs($hina)->postJson("/groups/{$group->id}/leave")->assertOk();
        $this->assertCount(2, $this->actingAs($ayesha)->getJson("/messages/{$message}/receipts")->json('data'));
    }
}

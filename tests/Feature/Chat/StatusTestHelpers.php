<?php

namespace Tests\Feature\Chat;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

/**
 * Shared setup for the Status (Phase 5) tests.
 */
trait StatusTestHelpers
{
    /** $owner saved $other in their phone book. */
    private function saveContact(User $owner, User $other): void
    {
        Contact::create(['user_id' => $owner->id, 'contact_user_id' => $other->id, 'name' => $other->name, 'phone' => '+92300'.str_pad((string) $other->id, 7, '0', STR_PAD_LEFT)]);
    }

    /** A one-to-one chat with a message between the two. */
    private function chatBetween(User $a, User $b): Conversation
    {
        $conversation = Conversation::factory()->between($a, $b)->create();
        $message = Message::factory()->inConversation($conversation, $a)->create();
        $conversation->forceFill(['last_message_id' => $message->id])->save();

        return $conversation;
    }

    private function textStatus(User $owner, string $text = 'Good morning ☀️'): int
    {
        return $this->actingAs($owner)->postJson('/statuses', ['text' => $text, 'background' => 'rose', 'font' => 2])->assertCreated()->json('id');
    }
}

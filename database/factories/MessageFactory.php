<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'message' => fake()->sentence(),
            'message_type' => Message::TYPE_TEXT,
            'sent_at' => now(),
        ];
    }

    /**
     * A message sent by $sender inside $conversation.
     */
    public function inConversation(Conversation $conversation, User $sender): static
    {
        return $this->state(fn () => [
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'receiver_id' => $conversation->otherParticipantId($sender),
        ]);
    }
}

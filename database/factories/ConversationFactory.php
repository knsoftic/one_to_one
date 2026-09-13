<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_one_id' => User::factory(),
            'user_two_id' => User::factory(),
        ];
    }

    public function between(User $a, User $b): static
    {
        [$one, $two] = Conversation::orderedPair($a, $b);

        return $this->state(fn () => ['user_one_id' => $one, 'user_two_id' => $two]);
    }
}

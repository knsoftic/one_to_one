<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Demo accounts and sample conversations for local development.
 * All demo accounts use the password "Password1".
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $people = [
            ['name' => 'Awais Ahmed', 'username' => 'awais', 'email' => 'awais@example.com', 'phone' => '+923001110001'],
            ['name' => 'Ahmed Khan', 'username' => 'ahmed', 'email' => 'ahmed@example.com', 'phone' => '+923001110002'],
            ['name' => 'Ali Raza', 'username' => 'ali', 'email' => 'ali@example.com', 'phone' => '+923001110003'],
            ['name' => 'Sara Malik', 'username' => 'sara', 'email' => 'sara@example.com', 'phone' => '+923001110004'],
            ['name' => 'Fatima Noor', 'username' => 'fatima', 'email' => 'fatima@example.com', 'phone' => '+923001110005'],
            ['name' => 'Usman Tariq', 'username' => 'usman', 'email' => 'usman@example.com', 'phone' => '+923001110006'],
        ];

        $users = collect($people)->mapWithKeys(fn (array $person) => [
            $person['username'] => User::query()->firstOrCreate(
                ['email' => $person['email']],
                $person + ['password' => 'Password1', 'email_verified_at' => now()],
            ),
        ]);

        if (Message::query()->exists()) {
            $this->command?->info('Demo users ready (messages already present, skipping sample chats).');

            return;
        }

        $now = now();

        $this->conversation($users['awais'], $users['ahmed'], [
            [$users['ahmed'], 'Assalam o Alaikum! Are we still on for the project review?', $now->copy()->subDays(2)->setTime(18, 5)],
            [$users['awais'], 'Walaikum Assalam! Yes, tomorrow at 11.', $now->copy()->subDays(2)->setTime(18, 7)],
            [$users['ahmed'], 'Perfect 👍', $now->copy()->subDays(2)->setTime(18, 7)],
            [$users['awais'], 'I pushed the latest changes to the repo, have a look when you can.', $now->copy()->subDay()->setTime(21, 40)],
            [$users['ahmed'], 'Just reviewed everything — looks great!', $now->copy()->subMinutes(42)],
            [$users['ahmed'], 'The new dashboard is really clean.', $now->copy()->subMinutes(41)],
            [$users['awais'], 'Thanks brother, glad you like it 😊', $now->copy()->subMinutes(30)],
            [$users['ahmed'], 'Hello brother', $now->copy()->subMinutes(5)],
        ], readByReceiverUntil: 7);

        $this->conversation($users['awais'], $users['ali'], [
            [$users['awais'], 'Ali, did you send the invoice to the client?', $now->copy()->subHours(3)],
            [$users['ali'], 'Okay, done', $now->copy()->subHours(2)->subMinutes(40)],
        ], readByReceiverUntil: 2);

        $this->conversation($users['awais'], $users['sara'], [
            [$users['sara'], 'Can you share the meeting notes?', $now->copy()->subDays(4)->setTime(10, 15)],
            [$users['awais'], 'Sure, sending them now 📄', $now->copy()->subDays(4)->setTime(10, 20)],
        ], readByReceiverUntil: 2);

        $this->conversation($users['ahmed'], $users['fatima'], [
            [$users['fatima'], 'See you at the event! 🎉', $now->copy()->subDays(1)->setTime(15, 0)],
        ], readByReceiverUntil: 1);

        $this->command?->info('Demo users and sample chats ready (password: Password1): '.collect($people)->pluck('email')->implode(', '));
    }

    /**
     * @param  array<int, array{0: User, 1: string, 2: Carbon}>  $lines
     * @param  int  $readByReceiverUntil  number of leading messages already delivered & seen
     */
    private function conversation(User $a, User $b, array $lines, int $readByReceiverUntil): void
    {
        [$one, $two] = Conversation::orderedPair($a, $b);
        $conversation = Conversation::query()->firstOrCreate(['user_one_id' => $one, 'user_two_id' => $two]);

        $last = null;

        foreach ($lines as $index => [$sender, $text, $at]) {
            $message = new Message([
                'conversation_id' => $conversation->id,
                'sender_id' => $sender->id,
                'receiver_id' => $conversation->otherParticipantId($sender),
                'message' => $text,
                'message_type' => Message::TYPE_TEXT,
                'sent_at' => $at,
            ]);

            $message->created_at = $at;
            $message->updated_at = $at;

            if ($index < $readByReceiverUntil) {
                $message->delivered_at = $at->copy()->addSeconds(5);
                $message->seen_at = $at->copy()->addMinute();
            }

            $message->save();
            $last = $message;
        }

        $conversation->forceFill(['last_message_id' => $last?->id])->save();
    }
}

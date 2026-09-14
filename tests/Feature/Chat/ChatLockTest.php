<?php

namespace Tests\Feature\Chat;

use App\Jobs\SendMessagePush;
use App\Models\ChatSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ContactService;
use App\Services\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * C9 — Chat lock: locked chats open only with the person's secret code.
 */
class ChatLockTest extends TestCase
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
        Message::factory()->inConversation($this->chat, $this->friend)->create(['message' => 'Secret plans']);
    }

    public function test_creating_and_changing_the_secret_code(): void
    {
        $this->actingAs($this->me)->patchJson("/conversations/{$this->chat->id}/settings", ['locked' => true])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Create a secret code first.');

        $this->actingAs($this->me)->postJson('/chat-lock/pin', ['pin' => '12a4', 'pin_confirmation' => '12a4'])->assertJsonValidationErrors('pin');
        $this->actingAs($this->me)->postJson('/chat-lock/pin', ['pin' => '1234', 'pin_confirmation' => '4321'])
            ->assertJsonValidationErrors(['pin' => 'The two codes do not match.']);

        $this->actingAs($this->me)->postJson('/chat-lock/pin', ['pin' => '1234', 'pin_confirmation' => '1234'])
            ->assertOk()
            ->assertJsonPath('enabled', true);
        $this->assertNotSame('1234', $this->me->fresh()->chat_lock_pin);
        $this->assertArrayNotHasKey('chat_lock_pin', $this->me->fresh()->toArray());

        // Changing it needs the account password.
        $this->actingAs($this->me)->postJson('/chat-lock/pin', ['pin' => '5678', 'pin_confirmation' => '5678'])->assertJsonValidationErrors('password');
        $this->actingAs($this->me)->postJson('/chat-lock/pin', ['pin' => '5678', 'pin_confirmation' => '5678', 'password' => 'Password1'])->assertOk();
    }

    public function test_a_locked_chat_hides_its_messages_until_the_code_is_entered(): void
    {
        $this->lockChat();
        $message = $this->chat->messages()->first();
        $this->actingAs($this->me)->putJson("/messages/{$message->id}/star")->assertStatus(423);

        // The chat list keeps only its place in "Locked chats".
        $this->actingAs($this->me)->getJson('/conversations')
            ->assertJsonPath('0.id', $this->chat->id)
            ->assertJsonPath('0.participant', null)
            ->assertJsonPath('0.last_message', null)
            ->assertJsonPath('0.unread_count', 1)
            ->assertJsonPath('0.settings.locked', true);

        $this->actingAs($this->me)->getJson("/conversations/{$this->chat->id}/messages")->assertStatus(423)->assertJsonPath('message', 'This chat is locked.');
        $this->actingAs($this->me)->getJson("/conversations/{$this->chat->id}")->assertStatus(423);
        $this->actingAs($this->me)->getJson('/chat/sync?since='.urlencode(now()->subMinute()->toIso8601String()))->assertOk()->assertJsonCount(0, 'messages');
        // Opening the page from a notification still works; the chat asks for the code.
        $this->actingAs($this->me)->get("/chat/{$this->chat->id}")->assertOk();
        // The other person is not affected.
        $this->actingAs($this->friend)->getJson("/conversations/{$this->chat->id}/messages")->assertOk();

        $this->actingAs($this->me)->postJson('/chat-lock/unlock', ['pin' => '0000'])->assertJsonValidationErrors(['pin' => 'Wrong secret code.']);
        $this->actingAs($this->me)->postJson('/chat-lock/unlock', ['pin' => '2580'])->assertOk()->assertJsonPath('enabled', true);

        $this->actingAs($this->me)->getJson("/conversations/{$this->chat->id}/messages")->assertOk()->assertJsonPath('data.0.body', 'Secret plans');
        $this->actingAs($this->me)->getJson('/conversations')->assertJsonPath('0.last_message.preview', 'Secret plans');

        // Closing the folder locks again; the unlock also ends by itself.
        $this->actingAs($this->me)->postJson('/chat-lock/lock')->assertOk()->assertJsonPath('unlocked_until', null);
        $this->actingAs($this->me)->getJson("/conversations/{$this->chat->id}/messages")->assertStatus(423);

        $this->actingAs($this->me)->postJson('/chat-lock/unlock', ['pin' => '2580'])->assertOk();
        $this->travel(11)->minutes();
        $this->actingAs($this->me)->getJson("/conversations/{$this->chat->id}/messages")->assertStatus(423);
    }

    public function test_locking_moves_the_chat_out_of_pins_archive_and_starred_messages(): void
    {
        $message = $this->chat->messages()->first();
        $this->actingAs($this->me)->putJson("/messages/{$message->id}/star")->assertSuccessful();
        $this->actingAs($this->me)->patchJson("/conversations/{$this->chat->id}/settings", ['pinned' => true])->assertOk();

        $this->lockChat();

        $setting = ChatSetting::query()->where('user_id', $this->me->id)->sole();
        $this->assertNotNull($setting->locked_at);
        $this->assertNull($setting->pinned_at);
        $this->actingAs($this->me)->getJson('/starred')->assertJsonCount(0, 'data');

        $this->actingAs($this->me)->postJson('/chat-lock/unlock', ['pin' => '2580'])->assertOk();
        $this->actingAs($this->me)->getJson('/starred')->assertJsonCount(1, 'data');

        // Unlocking the chat itself brings it back to the chat list.
        $this->actingAs($this->me)->patchJson("/conversations/{$this->chat->id}/settings", ['locked' => false])
            ->assertOk()
            ->assertJsonPath('settings.locked', false);
    }

    public function test_notifications_of_locked_chats_do_not_show_who_or_what(): void
    {
        $this->lockChat();

        $this->actingAs($this->friend)->postJson("/conversations/{$this->chat->id}/messages", ['message' => 'Meet at 5'])->assertCreated();

        $data = $this->me->notifications()->sole()->data;
        $this->assertSame('New message', $data['title']);
        $this->assertSame('', $data['body']);
        $this->assertSame(config('app.name'), $data['sender']['name']);
        $this->assertStringNotContainsString($this->friend->name, json_encode($data));

        $message = Message::query()->latest('id')->first();
        $this->mock(PushService::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendToUser')->once()->withArgs(fn ($user, array $payload) => $payload['sender_name'] === config('app.name')
                && $payload['body'] === ''
                && $payload['sender_id'] === 0
                && $payload['avatar_url'] === null);
        });
        (new SendMessagePush($message->id))->handle(app(PushService::class), app(ContactService::class));
    }

    public function test_forgotten_code_is_removed_with_the_password_and_chats_unlock(): void
    {
        $this->lockChat();

        $this->actingAs($this->me)->deleteJson('/chat-lock/pin', ['password' => 'wrong'])->assertJsonValidationErrors('password');
        $this->actingAs($this->me)->deleteJson('/chat-lock/pin', ['password' => 'Password1'])->assertOk()->assertJsonPath('enabled', false);

        $this->assertNull($this->me->fresh()->chat_lock_pin);
        $this->actingAs($this->me)->getJson("/conversations/{$this->chat->id}/messages")->assertOk();
    }

    public function test_code_attempts_are_rate_limited(): void
    {
        $this->lockChat();

        foreach (range(1, 5) as $attempt) {
            $this->actingAs($this->me)->postJson('/chat-lock/unlock', ['pin' => '1111'])->assertUnprocessable();
        }
        $this->actingAs($this->me)->postJson('/chat-lock/unlock', ['pin' => '2580'])->assertTooManyRequests();
    }

    /** Create the code 2580, lock the chat and close "Locked chats". */
    private function lockChat(): void
    {
        $this->actingAs($this->me)->postJson('/chat-lock/pin', ['pin' => '2580', 'pin_confirmation' => '2580'])->assertOk();
        $this->actingAs($this->me)->patchJson("/conversations/{$this->chat->id}/settings", ['locked' => true])->assertOk();
        $this->actingAs($this->me)->postJson('/chat-lock/lock')->assertOk();
    }
}

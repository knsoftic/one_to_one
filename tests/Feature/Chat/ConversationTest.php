<?php

namespace Tests\Feature\Chat;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_dashboard_renders_for_authenticated_users(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/chat')
            ->assertOk()
            ->assertSee('data-chat-app', false)
            ->assertSee('chat-config', false)
            ->assertSee('conversations\/__ID__\/messages', false)
            // Online people are shown as green dots in the chat list, not in a separate strip.
            ->assertDontSee('data-online-strip', false)
            ->assertDontSee('Online now');
    }

    public function test_starting_a_conversation_creates_it_once(): void
    {
        [$a, $b] = User::factory()->count(2)->create();

        $first = $this->actingAs($a)->postJson('/conversations', ['user_id' => $b->id])
            ->assertCreated()
            ->assertJsonPath('participant.id', $b->id);

        // Same pair from the other side returns the same conversation.
        $this->actingAs($b)->postJson('/conversations', ['user_id' => $a->id])
            ->assertOk()
            ->assertJsonPath('id', $first->json('id'))
            ->assertJsonPath('participant.id', $a->id);

        $this->assertSame(1, Conversation::count());
    }

    public function test_participants_are_stored_in_ascending_order(): void
    {
        [$a, $b] = User::factory()->count(2)->create();

        $conversation = Conversation::create(['user_one_id' => $b->id, 'user_two_id' => $a->id]);

        $this->assertSame(min($a->id, $b->id), $conversation->user_one_id);
        $this->assertSame(max($a->id, $b->id), $conversation->user_two_id);
    }

    public function test_database_rejects_duplicate_conversations(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        Conversation::create(['user_one_id' => $a->id, 'user_two_id' => $b->id]);

        $this->expectException(UniqueConstraintViolationException::class);
        Conversation::create(['user_one_id' => $b->id, 'user_two_id' => $a->id]);
    }

    public function test_service_find_or_create_is_idempotent(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        $service = app(ConversationService::class);

        $this->assertTrue($service->findOrCreate($a, $b)->is($service->findOrCreate($b, $a)));
    }

    public function test_users_cannot_start_a_conversation_with_unavailable_users(): void
    {
        $user = User::factory()->create();
        $suspended = User::factory()->suspended()->create();

        $this->actingAs($user)->postJson('/conversations', ['user_id' => $suspended->id])->assertUnprocessable();
        $this->actingAs($user)->postJson('/conversations', ['user_id' => 999999])->assertUnprocessable();
    }

    public function test_recent_conversations_are_sorted_by_latest_message_with_unread_counts(): void
    {
        [$me, $ahmed, $ali] = User::factory()->count(3)->create();
        $withAhmed = Conversation::factory()->between($me, $ahmed)->create();
        $withAli = Conversation::factory()->between($me, $ali)->create();
        Conversation::factory()->between($me, User::factory()->create())->create(); // empty: hidden

        Message::factory()->inConversation($withAli, $ali)->create(['message' => 'Okay, done']);
        Message::factory()->inConversation($withAhmed, $ahmed)->count(2)->create();
        Message::factory()->inConversation($withAhmed, $ahmed)->create(['message' => 'Hello brother']);

        $response = $this->actingAs($me)->getJson('/conversations')->assertOk();

        $response->assertJsonCount(2)
            ->assertJsonPath('0.participant.id', $ahmed->id)
            ->assertJsonPath('0.last_message.preview', 'Hello brother')
            ->assertJsonPath('0.unread_count', 3)
            ->assertJsonPath('1.participant.id', $ali->id)
            ->assertJsonPath('1.unread_count', 1);
    }

    public function test_participant_private_fields_are_not_exposed(): void
    {
        [$me, $other] = User::factory()->count(2)->create();
        $conversation = Conversation::factory()->between($me, $other)->create();

        $this->actingAs($me)->getJson("/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonMissingPath('participant.email')
            ->assertJsonMissingPath('participant.phone')
            ->assertJsonPath('participant.username', $other->username);
    }

    public function test_non_participants_cannot_access_a_conversation(): void
    {
        [$a, $b, $intruder] = User::factory()->count(3)->create();
        $conversation = Conversation::factory()->between($a, $b)->create();
        Message::factory()->inConversation($conversation, $a)->create(['message' => 'secret']);

        $this->actingAs($intruder)->getJson("/conversations/{$conversation->id}")->assertNotFound();
        $this->actingAs($intruder)->getJson("/conversations/{$conversation->id}/messages")->assertNotFound()->assertDontSee('secret');
        $this->actingAs($intruder)->get("/chat/{$conversation->id}")->assertNotFound();
        $this->actingAs($intruder)->postJson("/conversations/{$conversation->id}/messages", ['message' => 'hi'])->assertNotFound();

        $this->actingAs($intruder)->getJson('/conversations')->assertOk()->assertJsonCount(0);
    }

    public function test_admins_cannot_read_private_conversations_either(): void
    {
        [$a, $b] = User::factory()->count(2)->create();
        $admin = User::factory()->admin()->create();
        $conversation = Conversation::factory()->between($a, $b)->create();

        $this->actingAs($admin)->getJson("/conversations/{$conversation->id}/messages")->assertNotFound();
    }

    public function test_search_finds_users_by_name_username_email_and_phone(): void
    {
        $me = User::factory()->create();
        $target = User::factory()->create([
            'name' => 'Ahmed Raza', 'username' => 'ahmed.raza', 'email' => 'ahmed.r@example.com', 'phone' => '+923451234567',
        ]);
        User::factory()->suspended()->create(['name' => 'Ahmed Suspended']);

        foreach (['Ahmed', '@ahmed.raza', 'ahmed.r@example', '0345 1234', '3451234567'] as $term) {
            $this->actingAs($me)->getJson('/users/search?q='.urlencode($term))
                ->assertOk()
                ->assertJsonFragment(['id' => $target->id]);
        }

        $this->actingAs($me)->getJson('/users/search?q=Ahmed')
            ->assertJsonCount(1)
            ->assertJsonMissingPath('0.email');

        $this->actingAs($me)->getJson('/users/search?q='.urlencode($me->name))->assertJsonMissing(['id' => $me->id]);
    }

    public function test_search_escapes_like_wildcards(): void
    {
        $me = User::factory()->create(['name' => 'Me Myself', 'username' => 'me', 'email' => 'me@example.com']);
        User::factory()->create(['name' => 'Alpha Person', 'username' => 'alpha', 'email' => 'alpha@example.com']);
        $underscored = User::factory()->create(['name' => 'Beta Person', 'username' => 'beta_one', 'email' => 'beta@example.com']);

        // "%" and "_" are matched literally, not as LIKE wildcards.
        $this->actingAs($me)->getJson('/users/search?q=%25')->assertOk()->assertJsonCount(0);
        $this->actingAs($me)->getJson('/users/search?q=_')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $underscored->id);
    }

    public function test_online_users_endpoint_lists_recently_active_users(): void
    {
        $me = User::factory()->create();
        $online = User::factory()->online()->create();
        User::factory()->create(['is_online' => true, 'last_seen' => now()->subHour()]); // stale

        $this->actingAs($me)->getJson('/users/online')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $online->id)
            ->assertJsonPath('0.is_online', true);
    }
}

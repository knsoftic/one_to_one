<?php

namespace Tests\Feature\Chat;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create(['phone' => '+923000000001']);
    }

    public function test_phone_suffix_is_kept_in_sync(): void
    {
        $user = User::factory()->create(['phone' => '+923001234567']);
        $this->assertSame('001234567', $user->fresh()->phone_suffix);

        $user->update(['phone' => '+923339876543']);
        $this->assertSame('339876543', $user->fresh()->phone_suffix);
    }

    public function test_sync_matches_registered_users_in_any_phone_format(): void
    {
        $ahmed = User::factory()->create(['name' => 'Ahmed Khan', 'phone' => '+923001234567']);
        $sara = User::factory()->create(['name' => 'Sara Malik', 'phone' => '+923339876543']);
        User::factory()->create(['phone' => '+923110000000']); // registered but not in my phone book

        $response = $this->actingAs($this->me)->postJson('/contacts/sync', [
            'contacts' => [
                ['name' => 'Ahmed Bhai', 'phones' => ['0300 1234567']],
                ['name' => 'Sara Office', 'phones' => ['+44 20 7946 0000', '0092 333 9876543']],
                ['name' => 'Not Registered', 'phones' => ['0321 5550000']],
                ['name' => 'Myself', 'phones' => ['0300 0000001']],
                ['name' => 'Too Short', 'phones' => ['1122']],
            ],
        ])->assertOk();

        $response->assertJsonCount(2, 'matched')->assertJsonCount(2, 'data');

        $names = collect($response->json('data'))->mapWithKeys(fn ($c) => [$c['user']['id'] => $c['name']]);
        $this->assertSame('Ahmed Bhai', $names[$ahmed->id]);
        $this->assertSame('Sara Office', $names[$sara->id]);

        // Numbers of people who are not registered are never stored.
        $this->assertSame(2, Contact::count());
        $this->assertDatabaseMissing('contacts', ['phone' => '03215550000']);
        $response->assertJsonMissingPath('data.0.user.email');
    }

    public function test_sync_updates_saved_names_without_duplicates(): void
    {
        $ahmed = User::factory()->create(['phone' => '+923001234567']);

        $this->actingAs($this->me)->postJson('/contacts/sync', ['contacts' => [['name' => 'Ahmed', 'phones' => ['03001234567']]]]);
        $this->actingAs($this->me)->postJson('/contacts/sync', ['contacts' => [['name' => 'Ahmed Work', 'phones' => ['+923001234567']]]]);

        $this->assertSame(1, Contact::count());
        $this->assertSame('Ahmed Work', Contact::sole()->name);
        $this->assertSame($ahmed->id, Contact::sole()->contact_user_id);
    }

    public function test_suspended_users_are_not_matched_or_listed(): void
    {
        $suspended = User::factory()->suspended()->create(['phone' => '+923001234567']);

        $this->actingAs($this->me)
            ->postJson('/contacts/sync', ['contacts' => [['name' => 'Hidden', 'phones' => ['03001234567']]]])
            ->assertJsonCount(0, 'matched');

        Contact::create(['user_id' => $this->me->id, 'contact_user_id' => $suspended->id, 'name' => 'Old', 'phone' => '03001234567']);
        $this->actingAs($this->me)->getJson('/contacts')->assertJsonCount(0, 'data');
    }

    public function test_contacts_are_private_to_their_owner(): void
    {
        $ahmed = User::factory()->create();
        $other = User::factory()->create();
        $contact = Contact::create(['user_id' => $this->me->id, 'contact_user_id' => $ahmed->id, 'name' => 'Ahmed', 'phone' => '0300']);

        $this->actingAs($other)->getJson('/contacts')->assertJsonCount(0, 'data');
        $this->actingAs($other)->deleteJson("/contacts/{$contact->id}")->assertNotFound();

        $this->actingAs($this->me)->getJson('/contacts')->assertJsonPath('data.0.name', 'Ahmed');
        $this->actingAs($this->me)->deleteJson("/contacts/{$contact->id}")->assertNoContent();
        $this->assertSame(0, Contact::count());
    }

    public function test_conversations_show_the_saved_contact_name(): void
    {
        $ahmed = User::factory()->create(['name' => 'Ahmed Khan']);
        $conversation = Conversation::factory()->between($this->me, $ahmed)->create();
        Message::factory()->inConversation($conversation, $ahmed)->create();
        Contact::create(['user_id' => $this->me->id, 'contact_user_id' => $ahmed->id, 'name' => 'Ahmed Bhai', 'phone' => '03001234567']);

        $this->actingAs($this->me)->getJson('/conversations')
            ->assertJsonPath('0.participant.saved_name', 'Ahmed Bhai')
            ->assertJsonPath('0.participant.name', 'Ahmed Khan');

        // The other side has not saved me.
        $this->actingAs($ahmed)->getJson('/conversations')->assertJsonPath('0.participant.saved_name', null);
    }

    public function test_sync_validates_input_and_is_rate_limited(): void
    {
        $this->actingAs($this->me)->postJson('/contacts/sync', [])->assertUnprocessable();
        $this->actingAs($this->me)->postJson('/contacts/sync', ['contacts' => [['name' => 'x', 'phones' => []]]])->assertUnprocessable();

        // The two invalid attempts above also count towards the limit of 6 per minute.
        for ($i = 0; $i < 4; $i++) {
            $this->actingAs($this->me)->postJson('/contacts/sync', ['contacts' => [['name' => 'x', 'phones' => ['03001112223']]]])->assertOk();
        }
        $this->actingAs($this->me)->postJson('/contacts/sync', ['contacts' => [['name' => 'x', 'phones' => ['03001112223']]]])->assertTooManyRequests();
    }

    public function test_guests_cannot_use_contacts(): void
    {
        auth()->logout();
        $this->getJson('/contacts')->assertUnauthorized();
        $this->postJson('/contacts/sync', ['contacts' => [['phones' => ['1']]]])->assertUnauthorized();
    }
}

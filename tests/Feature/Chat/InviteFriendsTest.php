<?php

namespace Tests\Feature\Chat;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * C8 — Invite friends who are not on the app yet.
 */
class InviteFriendsTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create(['phone' => '+923000000001']);
    }

    public function test_sync_tells_which_phone_book_entries_can_be_invited(): void
    {
        User::factory()->create(['name' => 'Ahmed Khan', 'phone' => '+923001234567']);

        $this->actingAs($this->me)->postJson('/contacts/sync', [
            'contacts' => [
                ['name' => 'Ahmed Bhai', 'phones' => ['0300 1234567']],
                ['name' => 'Not Registered', 'phones' => ['0321 5550000']],
                ['name' => 'Myself', 'phones' => ['0300 0000001']],
                ['name' => 'Too Short', 'phones' => ['1122']],
                ['name' => 'Ahmed Work', 'phones' => ['+92 300 1234567']],
                ['name' => 'Two numbers', 'phones' => ['0322 5551111', '0323 5552222']],
            ],
        ])
            ->assertOk()
            ->assertJsonCount(1, 'matched')
            ->assertJsonPath('unmatched', [1, 5]);

        // The first entry names the contact; nobody else's number is stored.
        $this->assertSame('Ahmed Bhai', Contact::query()->sole()->name);
        $this->assertDatabaseMissing('contacts', ['phone' => '03215550000']);
    }

    public function test_the_chat_page_gets_the_invite_link(): void
    {
        $this->actingAs($this->me)->get('/chat')->assertOk()
            ->assertViewHas('chatConfig', fn (array $config) => $config['invite']['url'] === route('register'));

        config(['chat.invite.url' => 'https://example.com/one2one.apk']);

        $this->actingAs($this->me)->get('/chat')
            ->assertViewHas('chatConfig', fn (array $config) => $config['invite']['url'] === 'https://example.com/one2one.apk');
    }
}

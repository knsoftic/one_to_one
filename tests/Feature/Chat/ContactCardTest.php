<?php

namespace Tests\Feature\Chat;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M19 — Send a contact card.
 */
class ContactCardTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $friend;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->me, $this->friend] = User::factory()->count(2)->create();
        $this->conversation = Conversation::factory()->between($this->me, $this->friend)->create();
    }

    public function test_a_contact_card_links_to_an_account_only_from_my_saved_contacts(): void
    {
        $bilal = User::factory()->create(['name' => 'Bilal Ahmed', 'username' => 'bilal', 'phone' => '+923001234567']);
        Contact::query()->create(['user_id' => $this->me->id, 'contact_user_id' => $bilal->id, 'name' => 'Bilal Office', 'phone' => '+923001234567']);

        $this->send(['contact' => ['name' => "  Bilal\u{202E} Office ", 'phones' => ['0300-1234567', '+92 42 111 222 333']]])
            ->assertCreated()
            ->assertJsonPath('type', 'contact')
            ->assertJsonPath('contact.name', 'Bilal Office')
            ->assertJsonPath('contact.phones', ['03001234567', '+9242111222333'])
            ->assertJsonPath('contact.user.id', $bilal->id)
            ->assertJsonPath('contact.user.username', 'bilal');

        $this->assertSame('👤 Contact: Bilal Office', Message::sole()->preview());

        // A registered number that is not in my contacts is not revealed.
        User::factory()->create(['phone' => '+923331112223']);
        $this->send(['contact' => ['name' => 'Unknown', 'phones' => ['03331112223']]])
            ->assertCreated()
            ->assertJsonPath('contact.user', null);
    }

    public function test_invalid_contact_cards_are_refused(): void
    {
        $this->send(['contact' => ['name' => 'A', 'phones' => []]])->assertJsonValidationErrors('contact.phones');
        $this->send(['contact' => ['name' => 'A', 'phones' => ['<script>']]])->assertJsonValidationErrors('contact.phones.0');
        $this->send(['contact' => ['name' => str_repeat('x', 101), 'phones' => ['03001234567']]])->assertJsonValidationErrors('contact.name');
        $this->send(['contact' => ['name' => 'A', 'phones' => array_fill(0, 6, '03001234567')]])->assertJsonValidationErrors('contact.phones');
        $this->send(['contact' => ['name' => 'A', 'phones' => ['03001234567'], 'email' => 'x@y.z']])->assertJsonValidationErrors('contact');

        $this->assertSame(0, Message::count());
    }

    public function test_the_card_downloads_as_a_vcard_for_participants_only(): void
    {
        $id = $this->send(['contact' => ['name' => 'Ali; Khan, Jr', 'phones' => ['+923001234567']]])->json('id');

        $response = $this->actingAs($this->friend)->get("/messages/{$id}/contact.vcf")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/vcard; charset=utf-8');

        $this->assertStringContainsString('attachment; filename=ali-khan-jr.vcf', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString("FN:Ali\\; Khan\\, Jr\r\n", $response->getContent());
        $this->assertStringContainsString("TEL;TYPE=CELL:+923001234567\r\n", $response->getContent());

        $this->actingAs(User::factory()->create())->get("/messages/{$id}/contact.vcf")->assertNotFound();
    }

    public function test_forwarding_keeps_the_card(): void
    {
        $id = $this->send(['contact' => ['name' => 'Sara', 'phones' => ['+923001234567']]])->json('id');
        $other = Conversation::factory()->between($this->me, User::factory()->create())->create();

        $this->actingAs($this->me)->postJson("/messages/{$id}/forward", ['conversation_ids' => [$other->id]])
            ->assertCreated()
            ->assertJsonPath('data.0.type', 'contact')
            ->assertJsonPath('data.0.contact.name', 'Sara');
    }

    private function send(array $payload)
    {
        return $this->actingAs($this->me)->postJson("/conversations/{$this->conversation->id}/messages", $payload);
    }
}

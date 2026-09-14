<?php

namespace Tests\Feature\Privacy;

use App\Models\BlockedUser;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P5 — Blocked contacts in settings: list, block someone you chat with, unblock.
 */
class BlockedContactsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_list_blocked_contacts_and_block_or_unblock_from_there(): void
    {
        $me = User::factory()->create();
        $bilal = User::factory()->create(['name' => 'Bilal Ahmed']);
        $sara = User::factory()->create(['name' => 'Sara Khan']);
        $stranger = User::factory()->create(['name' => 'Stranger Danger']);
        Contact::create(['user_id' => $me->id, 'contact_user_id' => $sara->id, 'name' => 'Sara Office', 'phone' => '+923001234567']);
        foreach ([$bilal, $sara] as $person) {
            $chat = Conversation::factory()->between($me, $person)->create();
            $chat->forceFill(['last_message_id' => Message::factory()->inConversation($chat, $person)->create()->id])->save();
        }

        // People you chat with can be blocked from settings (strangers aren't listed).
        $this->actingAs($me)->get('/settings?tab=blocked')
            ->assertOk()
            ->assertSee('Blocked contacts')
            ->assertSee('No blocked contacts')
            ->assertSee('Sara Office')
            ->assertSee('Bilal Ahmed')
            ->assertDontSee('Stranger Danger');

        $this->actingAs($me)->from('/settings?tab=blocked')->post("/users/{$bilal->id}/block")
            ->assertRedirect('/settings?tab=blocked')
            ->assertSessionHas('status');
        $this->assertTrue(BlockedUser::query()->where('user_id', $me->id)->where('blocked_user_id', $bilal->id)->exists());

        $page = $this->actingAs($me)->get('/settings?tab=blocked')->assertOk()->assertSee('(1)', false);
        $this->assertStringContainsString('data-blocked-row="'.$bilal->id.'"', $page->getContent());
        $this->assertStringNotContainsString('data-block-candidate data-name="bilal ahmed', $page->getContent());
        $this->actingAs($me)->get('/settings?tab=privacy')->assertSee('1 person');

        $this->actingAs($me)->from('/settings?tab=blocked')->delete("/users/{$bilal->id}/block")->assertRedirect('/settings?tab=blocked');
        $this->assertSame(0, BlockedUser::count());
    }
}

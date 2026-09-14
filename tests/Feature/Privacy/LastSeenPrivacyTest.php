<?php

namespace Tests\Feature\Privacy;

use App\Events\UserPresenceChanged;
use App\Models\BlockedUser;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\PresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * P1 — Last seen and online privacy.
 */
class LastSeenPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private User $ayesha;

    private User $bilal;

    private User $sara;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->ayesha, $this->bilal, $this->sara] = [
            User::factory()->create(['name' => 'Ayesha', 'username' => 'ayesha']),
            User::factory()->create(['name' => 'Bilal']),
            User::factory()->create(['name' => 'Sara']),
        ];

        // Ayesha chats with Bilal (she wrote to him); Sara is a stranger to her.
        $chat = Conversation::factory()->between($this->ayesha, $this->bilal)->create();
        Message::factory()->inConversation($chat, $this->bilal)->create();
        $message = Message::factory()->inConversation($chat, $this->ayesha)->create();
        $chat->forceFill(['last_message_id' => $message->id])->save();

        app(PresenceService::class)->touch($this->ayesha, force: true);
    }

    public function test_everyone_my_contacts_and_nobody(): void
    {
        $this->assertSeesPresence($this->bilal, online: true, lastSeen: true);
        $this->assertSeesPresence($this->sara, online: true, lastSeen: true);

        $this->ayesha->forceFill(['last_seen_privacy' => 'contacts'])->save();
        $this->assertSeesPresence($this->bilal, online: true, lastSeen: true);
        $this->assertSeesPresence($this->sara, online: true, lastSeen: false);

        // A stranger's unanswered message doesn't make them her contact.
        $strangerChat = Conversation::factory()->between($this->ayesha, $this->sara)->create();
        $hello = Message::factory()->inConversation($strangerChat, $this->sara)->create();
        $strangerChat->forceFill(['last_message_id' => $hello->id])->save();
        $this->assertSeesPresence($this->sara, online: true, lastSeen: false);

        // Someone who saved Ayesha isn't her contact; someone Ayesha saved is.
        Contact::create(['user_id' => $this->sara->id, 'contact_user_id' => $this->ayesha->id, 'name' => 'A', 'phone' => '+923001111111']);
        $this->assertSeesPresence($this->sara, online: true, lastSeen: false);
        Contact::create(['user_id' => $this->ayesha->id, 'contact_user_id' => $this->sara->id, 'name' => 'S', 'phone' => '+923002222222']);
        $this->assertSeesPresence($this->sara, online: true, lastSeen: true);

        // Online "same as last seen" follows the last seen choice.
        $this->ayesha->forceFill(['last_seen_privacy' => 'nobody', 'online_privacy' => 'same'])->save();
        $this->assertSeesPresence($this->bilal, online: false, lastSeen: false);
        $this->ayesha->forceFill(['online_privacy' => 'everyone'])->save();
        $this->assertSeesPresence($this->bilal, online: true, lastSeen: false);
        $this->assertContains($this->ayesha->id, $this->onlineIds($this->sara));
        $this->ayesha->forceFill(['online_privacy' => 'same'])->save();
        $this->assertNotContains($this->ayesha->id, $this->onlineIds($this->sara));
    }

    public function test_hiding_your_last_seen_hides_others_and_blocks_hide_everything(): void
    {
        $this->bilal->forceFill(['last_seen_privacy' => 'nobody'])->save();
        $this->assertSeesPresence($this->bilal, online: true, lastSeen: false);

        $this->bilal->forceFill(['last_seen_privacy' => 'everyone'])->save();
        BlockedUser::create(['user_id' => $this->ayesha->id, 'blocked_user_id' => $this->bilal->id]);
        $this->assertSeesPresence($this->bilal, online: false, lastSeen: false);
    }

    public function test_presence_changes_reach_only_people_allowed(): void
    {
        Event::fake([UserPresenceChanged::class]);
        Contact::create(['user_id' => $this->sara->id, 'contact_user_id' => $this->ayesha->id, 'name' => 'A', 'phone' => '+923001111111']);
        $this->ayesha->forceFill(['last_seen_privacy' => 'contacts', 'online_privacy' => 'same'])->save();

        app(PresenceService::class)->markOffline($this->ayesha);

        // Bilal (her chat) hears everything; Sara (saved her, not her contact) hears nothing.
        Event::assertDispatched(UserPresenceChanged::class, fn (UserPresenceChanged $e) => $e->recipientIds === [$this->bilal->id] && $e->lastSeen !== null);
        Event::assertDispatchedTimes(UserPresenceChanged::class, 1);

        $this->ayesha->forceFill(['last_seen_privacy' => 'nobody', 'online_privacy' => 'everyone'])->save();
        app(PresenceService::class)->touch($this->ayesha, force: true);
        Event::assertDispatched(UserPresenceChanged::class, fn (UserPresenceChanged $e) => $e->isOnline && $e->lastSeen === null
            && collect($e->recipientIds)->sort()->values()->all() === collect([$this->bilal->id, $this->sara->id])->sort()->values()->all());
    }

    public function test_privacy_choices_are_saved_from_settings(): void
    {
        $this->actingAs($this->ayesha)->patchJson('/settings/preferences', ['last_seen_privacy' => 'contacts', 'online_privacy' => 'same'])
            ->assertOk()
            ->assertJsonPath('preferences.last_seen_privacy', 'contacts')
            ->assertJsonPath('user.online_privacy', 'same');
        $this->actingAs($this->ayesha)->patchJson('/settings/preferences', ['last_seen_privacy' => 'friends'])->assertJsonValidationErrors('last_seen_privacy');
        $this->actingAs($this->ayesha)->get('/settings?tab=privacy')->assertOk()->assertSee('Last seen')->assertSee('Read receipts');
    }

    /**
     * @return list<int>
     */
    private function onlineIds(User $viewer): array
    {
        return collect($this->actingAs($viewer)->getJson('/users/online')->assertOk()->json())->pluck('id')->all();
    }

    private function assertSeesPresence(User $viewer, bool $online, bool $lastSeen): void
    {
        $user = collect($this->actingAs($viewer)->getJson('/users/search?q=ayesha')->assertOk()->json())->firstWhere('id', $this->ayesha->id);

        $this->assertSame($online, $user['is_online'], 'online for '.$viewer->name);
        $this->assertSame($lastSeen, $user['last_seen'] !== null, 'last seen for '.$viewer->name);
    }
}

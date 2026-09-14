<?php

namespace Tests\Feature\Privacy;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P2 — Profile photo and About privacy; P4 — About.
 */
class ProfilePrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_about_is_saved_on_the_profile_and_shown_to_others(): void
    {
        $ayesha = User::factory()->create(['name' => 'Ayesha', 'username' => 'ayesha']);
        $bilal = User::factory()->create();

        $this->actingAs($ayesha)->put('/settings/profile', [
            'name' => $ayesha->name,
            'username' => $ayesha->username,
            'email' => $ayesha->email,
            'phone' => $ayesha->phone,
            'about' => '   At   work 💼  ',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('At work 💼', $ayesha->fresh()->about);

        $this->actingAs($ayesha)->put('/settings/profile', [
            'name' => $ayesha->name, 'username' => $ayesha->username, 'email' => $ayesha->email, 'phone' => $ayesha->phone,
            'about' => str_repeat('a', 140),
        ])->assertSessionHasErrors('about', null, 'profile');

        $this->actingAs($bilal)->getJson('/users/search?q=ayesha')->assertJsonPath('0.about', 'At work 💼');
    }

    public function test_photo_and_about_follow_their_privacy(): void
    {
        $ayesha = User::factory()->create(['username' => 'ayesha', 'about' => 'Available', 'profile_image' => 'avatars/ayesha.webp']);
        $bilal = User::factory()->create();
        $sara = User::factory()->create();
        $chat = Conversation::factory()->between($ayesha, $bilal)->create();
        $message = Message::factory()->inConversation($chat, $ayesha)->create();
        $chat->forceFill(['last_message_id' => $message->id])->save();

        $ayesha->forceFill(['photo_privacy' => 'contacts', 'about_privacy' => 'nobody'])->save();

        $bilalView = $this->actingAs($bilal)->getJson('/conversations')->json('0.participant');
        $this->assertStringEndsWith('avatars/ayesha.webp', $bilalView['avatar_url']);
        $this->assertNull($bilalView['about']);

        $saraView = $this->actingAs($sara)->getJson('/users/search?q=ayesha')->json('0');
        $this->assertNull($saraView['avatar_url']);
        $this->assertNull($saraView['about']);

        // She sees her own details and settings.
        $this->actingAs($ayesha)->getJson('/users/search?q='.$bilal->username)->assertOk();
        $this->actingAs($ayesha)->patchJson('/settings/preferences', ['photo_privacy' => 'nobody'])
            ->assertJsonPath('user.photo_privacy', 'nobody')
            ->assertJsonPath('user.about', 'Available');
        $this->assertNull($this->actingAs($bilal)->getJson('/conversations')->json('0.participant.avatar_url'));
    }

    public function test_notifications_leave_out_a_hidden_photo(): void
    {
        $ayesha = User::factory()->create(['profile_image' => 'avatars/ayesha.webp', 'photo_privacy' => 'nobody']);
        $bilal = User::factory()->create();
        $chat = Conversation::factory()->between($ayesha, $bilal)->create();

        $this->actingAs($ayesha)->postJson("/conversations/{$chat->id}/messages", ['message' => 'Salam'])->assertCreated();

        $notification = $bilal->notifications()->sole();
        $this->assertNull($notification->data['sender']['avatar_url']);
    }
}

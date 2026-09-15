<?php

namespace Tests\Feature\Chat;

use App\Models\ChatSetting;
use App\Models\Conversation;
use App\Models\DeviceToken;
use App\Models\User;
use App\Services\DeviceService;
use App\Support\ChatPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ConfiguresFirebase;
use Tests\TestCase;

/**
 * D4 — notification tone and vibration, for all chats and per chat.
 */
class NotificationToneTest extends TestCase
{
    use ConfiguresFirebase, RefreshDatabase;

    private User $me;

    private User $friend;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->me, $this->friend] = User::factory()->count(2)->create();
        $this->conversation = Conversation::factory()->between($this->me, $this->friend)->create();
    }

    public function test_default_tone_and_vibration_in_settings(): void
    {
        $this->assertSame('default', $this->me->fresh()->notification_tone);

        $this->actingAs($this->me)->patchJson('/settings/preferences', ['notification_tone' => 'bell', 'notification_vibrate' => 'long'])
            ->assertOk()
            ->assertJsonPath('preferences.notification_tone', 'bell')
            ->assertJsonPath('preferences.notification_vibrate', 'long');

        $this->patchJson('/settings/preferences', ['notification_tone' => 'siren'])->assertJsonValidationErrors('notification_tone');
        $this->patchJson('/settings/preferences', ['notification_tone' => 'none'])->assertJsonValidationErrors('notification_tone');
        $this->patchJson('/settings/preferences', ['notification_vibrate' => 'buzz'])->assertJsonValidationErrors('notification_vibrate');

        $this->get('/settings?tab=notifications')->assertOk()->assertSee('Notification tone')->assertSee('Vibration');
    }

    public function test_a_chat_can_have_its_own_tone(): void
    {
        $id = $this->conversation->id;

        $this->actingAs($this->me)->patchJson("/conversations/{$id}/settings", ['notification_tone' => 'marimba', 'notification_vibrate' => 'off'])
            ->assertOk()
            ->assertJsonPath('settings.notification_tone', 'marimba')
            ->assertJsonPath('settings.notification_vibrate', 'off');

        $this->patchJson("/conversations/{$id}/settings", ['notification_tone' => 'none'])->assertOk()->assertJsonPath('settings.notification_tone', 'none');
        $this->patchJson("/conversations/{$id}/settings", ['notification_tone' => 'loud'])->assertStatus(422);

        // "default" and null both go back to the choice from Settings.
        $this->patchJson("/conversations/{$id}/settings", ['notification_tone' => 'default', 'notification_vibrate' => null])
            ->assertOk()
            ->assertJsonPath('settings.notification_tone', null)
            ->assertJsonPath('settings.notification_vibrate', null);
    }

    public function test_alert_prefers_the_chat_then_settings_and_sound_off_means_none(): void
    {
        $user = new User(['notification_tone' => 'glass', 'notification_vibrate' => 'short']);
        $user->notification_sound = true;

        $this->assertSame(['tone' => 'glass', 'vibrate' => 'short'], ChatPreferences::alertFor($user));
        $this->assertSame(['tone' => 'pop', 'vibrate' => 'short'], ChatPreferences::alertFor($user, (new ChatSetting)->forceFill(['notification_tone' => 'pop'])));

        $user->notification_sound = false;
        $this->assertSame(['tone' => 'none', 'vibrate' => 'short'], ChatPreferences::alertFor($user));
        // A chat's own tone still plays: it was chosen on purpose.
        $setting = new ChatSetting;
        $setting->forceFill(['notification_tone' => 'bell', 'notification_vibrate' => 'off']);
        $this->assertSame(['tone' => 'bell', 'vibrate' => 'off'], ChatPreferences::alertFor($user, $setting));
    }

    public function test_notification_push_and_phone_feed_carry_the_tone(): void
    {
        $this->configureFirebase();
        $this->fakeFirebase();
        $this->me->forceFill(['notification_tone' => 'chirp'])->save();
        $device = app(DeviceService::class)->issue($this->me, 'android')['device'];
        $device->forceFill(['fcm_token' => 'fcm-token-abcdefghijklmnopqrstuvwxyz0123456789', 'fcm_token_hash' => DeviceToken::hashToken('fcm-token-abcdefghijklmnopqrstuvwxyz0123456789')])->save();
        ChatSetting::query()->create(['user_id' => $this->me->id, 'conversation_id' => $this->conversation->id])->forceFill(['notification_vibrate' => 'long'])->save();

        $this->actingAs($this->friend)->postJson("/conversations/{$this->conversation->id}/messages", ['message' => 'Tone check'])->assertCreated();

        $notification = $this->me->notifications()->sole();
        $this->assertSame('chirp', $notification->data['tone']);
        $this->assertSame('long', $notification->data['vibrate']);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'messages:send')
            && $request['message']['data']['tone'] === 'chirp'
            && $request['message']['data']['vibrate'] === 'long');

        $feed = app(DeviceService::class)->feed($this->me, null);
        $this->assertSame('chirp', $feed['data'][0]['tone']);
        $this->assertSame('long', $feed['data'][0]['vibrate']);
    }

    public function test_group_members_get_their_own_chat_tone(): void
    {
        $id = $this->actingAs($this->friend)->postJson('/groups', ['name' => 'Team', 'member_ids' => [$this->me->id]])->assertCreated()->json('id');
        $setting = ChatSetting::query()->create(['user_id' => $this->me->id, 'conversation_id' => $id]);
        $setting->forceFill(['notification_tone' => 'none'])->save();

        $this->postJson("/conversations/{$id}/messages", ['message' => 'Hello team'])->assertCreated();

        $notification = $this->me->notifications()->get()->firstWhere('data.body', 'Hello team');
        $this->assertSame('none', $notification->data['tone']);
    }
}

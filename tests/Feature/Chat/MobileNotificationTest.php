<?php

namespace Tests\Feature\Chat;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\DeviceToken;
use App\Models\User;
use App\Services\DeviceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Background notifications of the mobile app without Firebase: device tokens,
 * private channel authorization and the polling feed.
 */
class MobileNotificationTest extends TestCase
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

        config([
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
        ]);
    }

    public function test_app_gets_a_device_token_and_connection_details(): void
    {
        $response = $this->actingAs($this->me)
            ->postJson('/devices', ['platform' => 'android', 'app_version' => '1.0'])
            ->assertCreated()
            ->assertJsonPath('user_id', $this->me->id)
            ->assertJsonPath('channel', "private-App.Models.User.{$this->me->id}")
            ->assertJsonPath('endpoints.auth', route('device.broadcasting.auth'))
            ->assertJsonPath('endpoints.notifications', route('device.notifications'))
            ->assertJsonStructure(['token', 'server_url', 'server_time', 'websocket_url', 'poll_interval_seconds', 'show_preview']);

        $token = $response->json('token');
        $this->assertSame(64, strlen($token));

        // Only the hash is stored.
        $device = DeviceToken::sole();
        $this->assertSame(hash('sha256', $token), $device->token_hash);
        $this->assertSame('1.0', $device->app_version);
        $this->assertDatabaseMissing('device_tokens', ['token_hash' => $token]);
    }

    public function test_registering_again_from_the_same_phone_replaces_the_old_token(): void
    {
        $first = $this->actingAs($this->me)->postJson('/devices', ['platform' => 'android'])->json('token');
        $second = $this->actingAs($this->me)->postJson('/devices', ['platform' => 'android'])->json('token');

        $this->assertNotSame($first, $second);
        $this->assertSame(1, DeviceToken::count());
        $this->withToken($first)->getJson('/api/device/notifications')->assertUnauthorized();
        $this->withToken($second)->getJson('/api/device/notifications')->assertOk();
    }

    public function test_device_registration_requires_login_and_valid_data(): void
    {
        $this->postJson('/devices', ['platform' => 'android'])->assertUnauthorized();

        $this->actingAs($this->me)
            ->postJson('/devices', ['platform' => 'windows', 'app_version' => '<script>'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['platform', 'app_version']);
    }

    public function test_websocket_address_follows_the_reverb_configuration(): void
    {
        config(['broadcasting.default' => 'null']);
        $this->actingAs($this->me)->postJson('/devices', ['platform' => 'android'])->assertJsonPath('websocket_url', null);

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.options' => ['host' => 'chat.example.com', 'port' => 443, 'scheme' => 'https'],
        ]);
        $this->actingAs($this->me)
            ->postJson('/devices', ['platform' => 'android'])
            ->assertJsonPath('websocket_url', 'wss://chat.example.com:443/app/test-key?protocol=7&client=one2one-android&version=1.0&flash=false');
    }

    public function test_device_api_rejects_missing_or_unknown_tokens(): void
    {
        $this->getJson('/api/device/notifications')->assertUnauthorized();
        $this->withToken('not-a-real-token')->getJson('/api/device/notifications')->assertUnauthorized();
        $this->withToken(str_repeat('x', 500))->postJson('/api/device/broadcasting/auth')->assertUnauthorized();
    }

    public function test_suspended_accounts_lose_their_devices(): void
    {
        $token = $this->issueToken($this->me);
        $this->me->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->withToken($token)->getJson('/api/device/notifications')->assertUnauthorized();
        $this->assertSame(0, DeviceToken::count());
    }

    public function test_app_can_subscribe_only_to_its_own_private_channel(): void
    {
        $token = $this->issueToken($this->me);
        $channel = "private-App.Models.User.{$this->me->id}";

        $this->withToken($token)
            ->postJson('/api/device/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])
            ->assertOk()
            ->assertExactJson(['auth' => 'test-key:'.hash_hmac('sha256', "1234.5678:{$channel}", 'test-secret')]);

        $this->withToken($token)
            ->postJson('/api/device/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => "private-App.Models.User.{$this->friend->id}"])
            ->assertForbidden();

        $this->withToken($token)
            ->postJson('/api/device/broadcasting/auth', ['socket_id' => 'abc', 'channel_name' => $channel])
            ->assertUnprocessable();
    }

    public function test_feed_returns_new_unread_messages_with_saved_contact_names(): void
    {
        $token = $this->issueToken($this->me);
        Contact::create(['user_id' => $this->me->id, 'contact_user_id' => $this->friend->id, 'name' => 'Ali Bhai', 'phone' => '03001234567']);

        $this->sendFromFriend('First message');
        $this->sendFromFriend('Second message');
        $this->travel(5)->seconds();

        $response = $this->withToken($token)->getJson('/api/device/notifications')->assertOk();

        $response->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.body', 'First message')
            ->assertJsonPath('data.1.body', 'Second message')
            ->assertJsonPath('data.1.type', 'message.notification')
            ->assertJsonPath('data.1.conversation_id', $this->conversation->id)
            ->assertJsonPath('data.1.sender.display_name', 'Ali Bhai')
            ->assertJsonPath('unread_conversation_ids', [$this->conversation->id])
            ->assertJsonMissingPath('data.0.sender.email');

        // Only notifications from the returned cursor onwards come back (the app de-duplicates by id).
        $this->withToken($token)
            ->getJson('/api/device/notifications?after='.urlencode($response->json('server_time')))
            ->assertJsonCount(0, 'data');

        $this->sendFromFriend('Third message');
        $this->withToken($token)
            ->getJson('/api/device/notifications?after='.urlencode($response->json('server_time')))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'Third message');
    }

    public function test_feed_skips_messages_already_seen_and_respects_the_notification_setting(): void
    {
        $token = $this->issueToken($this->me);
        $this->sendFromFriend('Hello');

        $this->actingAs($this->me)->postJson("/conversations/{$this->conversation->id}/seen")->assertSuccessful();

        $this->withToken($token)
            ->getJson('/api/device/notifications')
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('unread_conversation_ids', []);

        $this->sendFromFriend('Are you there?');
        $this->me->update(['notifications_enabled' => false]);

        $this->withToken($token)->getJson('/api/device/notifications')->assertJsonCount(0, 'data');
    }

    public function test_feed_never_contains_other_users_notifications(): void
    {
        $token = $this->issueToken($this->friend);
        $this->sendFromFriend('Only for me');

        $this->withToken($token)->getJson('/api/device/notifications')->assertJsonCount(0, 'data');
    }

    public function test_app_can_sign_its_device_out(): void
    {
        $token = $this->issueToken($this->me);

        $this->withToken($token)->deleteJson('/api/device')->assertNoContent();

        $this->assertSame(0, DeviceToken::count());
        $this->withToken($token)->getJson('/api/device/notifications')->assertUnauthorized();
    }

    public function test_logging_out_signs_out_only_this_phone(): void
    {
        $this->actingAs($this->me)->postJson('/devices', ['platform' => 'android'])->assertCreated();
        $otherPhone = $this->issueToken($this->me);

        $this->post('/logout')->assertRedirect(route('login'));

        $this->assertSame(1, DeviceToken::count());
        $this->withToken($otherPhone)->getJson('/api/device/notifications')->assertOk();
    }

    public function test_changing_the_password_signs_out_all_phones(): void
    {
        $this->issueToken($this->me);
        $this->issueToken($this->me);

        $this->actingAs($this->me)->put('/settings/password', [
            'current_password' => 'Password1',
            'password' => 'BrandNew123',
            'password_confirmation' => 'BrandNew123',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, DeviceToken::count());
    }

    private function issueToken(User $user): string
    {
        return app(DeviceService::class)->issue($user, 'android')['token'];
    }

    private function sendFromFriend(string $text): void
    {
        $this->actingAs($this->friend)
            ->postJson("/conversations/{$this->conversation->id}/messages", ['message' => $text])
            ->assertCreated();
    }
}

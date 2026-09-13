<?php

namespace Tests\Feature\Chat;

use App\Models\BlockedUser;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\DeviceToken;
use App\Models\Message;
use App\Models\User;
use App\Services\DeviceService;
use App\Services\PushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Firebase push notifications (WhatsApp style) and the actions on a
 * notification: delivered ticks, Reply and Mark as read.
 */
class PushNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const FCM_TOKEN = 'fcm-token-1234567890:APA91bExampleDeviceToken_abcdefghijklmnopqrstuvwxyz';

    private User $me;

    private User $friend;

    private Conversation $conversation;

    private ?string $credentialsPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->me, $this->friend] = User::factory()->count(2)->create();
        $this->conversation = Conversation::factory()->between($this->me, $this->friend)->create();
    }

    protected function tearDown(): void
    {
        if ($this->credentialsPath && is_file($this->credentialsPath)) {
            unlink($this->credentialsPath);
        }

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ */
    /* Push token */
    /* ------------------------------------------------------------------ */

    public function test_phone_saves_and_removes_its_firebase_token(): void
    {
        ['token' => $token, 'device' => $device] = $this->issue($this->me);

        $this->withToken($token)->putJson('/api/device/push-token', ['token' => self::FCM_TOKEN])->assertOk();
        $this->assertSame(self::FCM_TOKEN, $device->fresh()->fcm_token);

        $this->withToken($token)->putJson('/api/device/push-token', ['token' => null])->assertOk();
        $this->assertNull($device->fresh()->fcm_token);

        $this->withToken($token)
            ->putJson('/api/device/push-token', ['token' => "not valid\n"])
            ->assertJsonValidationErrors('token');
    }

    public function test_a_firebase_token_belongs_to_one_account_only(): void
    {
        ['token' => $old, 'device' => $oldDevice] = $this->issue($this->me);
        ['token' => $new, 'device' => $newDevice] = $this->issue($this->friend);

        $this->withToken($old)->putJson('/api/device/push-token', ['token' => self::FCM_TOKEN])->assertOk();
        // Same phone signed into another account without signing out first.
        $this->withToken($new)->putJson('/api/device/push-token', ['token' => self::FCM_TOKEN])->assertOk();

        $this->assertNull($oldDevice->fresh()->fcm_token);
        $this->assertSame(self::FCM_TOKEN, $newDevice->fresh()->fcm_token);
    }

    public function test_connection_details_say_whether_firebase_push_is_available(): void
    {
        $this->actingAs($this->me)->postJson('/devices', ['platform' => 'android'])
            ->assertJsonPath('push.fcm', false)
            ->assertJsonPath('endpoints.reply', route('device.reply', ['conversation' => '__ID__']));

        $this->configureFirebase();

        $this->actingAs($this->me)->postJson('/devices', ['platform' => 'android'])->assertJsonPath('push.fcm', true);
    }

    /* ------------------------------------------------------------------ */
    /* Sending */
    /* ------------------------------------------------------------------ */

    public function test_new_message_is_pushed_as_a_data_message_with_sender_details(): void
    {
        $this->configureFirebase();
        $this->fakeFirebase();
        $this->withFcmToken($this->me);
        Contact::create(['user_id' => $this->me->id, 'contact_user_id' => $this->friend->id, 'name' => 'Ali Bhai', 'phone' => '03001234567']);

        $this->sendFromFriend('Assalam o Alaikum');

        $message = Message::sole();
        $notificationId = $this->me->notifications()->sole()->id;

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'oauth2.googleapis.com/token')
            && $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
            && count(explode('.', $request['assertion'])) === 3);

        Http::assertSent(function (Request $request) use ($message, $notificationId) {
            if (! str_contains($request->url(), 'fcm.googleapis.com/v1/projects/one2one-test/messages:send')) {
                return false;
            }

            $payload = $request['message'];

            return $request->hasHeader('Authorization', 'Bearer test-access-token')
                && $payload['token'] === self::FCM_TOKEN
                && ! isset($payload['notification']) // the app draws the notification itself
                && $payload['android']['priority'] === 'HIGH'
                && $payload['data']['type'] === 'message'
                && $payload['data']['id'] === $notificationId
                && $payload['data']['conversation_id'] === (string) $this->conversation->id
                && $payload['data']['message_id'] === (string) $message->id
                && $payload['data']['sender_name'] === 'Ali Bhai'
                && $payload['data']['initials'] === $this->friend->initials
                && $payload['data']['body'] === 'Assalam o Alaikum'
                && ctype_digit($payload['data']['sent_at']);
        });
    }

    public function test_no_push_without_firebase_credentials_or_when_notifications_are_off(): void
    {
        Http::fake();
        $this->withFcmToken($this->me);

        $this->sendFromFriend('Hello');
        Http::assertNothingSent();

        $this->configureFirebase();
        $this->me->update(['notifications_enabled' => false]);

        $this->sendFromFriend('Hello again');
        Http::assertNothingSent();
    }

    public function test_reading_a_chat_removes_its_notification_from_the_phones(): void
    {
        $this->sendFromFriend('Hello');
        $this->configureFirebase();
        $this->fakeFirebase();
        $this->withFcmToken($this->me);

        $this->actingAs($this->me)->postJson("/conversations/{$this->conversation->id}/seen")->assertOk();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'messages:send')
            && $request['message']['data'] === ['type' => 'read', 'conversation_id' => (string) $this->conversation->id]
            && $request['message']['android']['priority'] === 'NORMAL');
    }

    public function test_tokens_of_uninstalled_apps_are_cleared(): void
    {
        $this->configureFirebase();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-access-token', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response([
                'error' => ['code' => 404, 'status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]],
            ], 404),
        ]);
        $device = $this->withFcmToken($this->me);

        $this->sendFromFriend('Are you there?');

        $this->assertNull($device->fresh()->fcm_token);
        $this->assertNotNull($device->fresh()); // the phone keeps its background connection
    }

    /* ------------------------------------------------------------------ */
    /* Notification actions */
    /* ------------------------------------------------------------------ */

    public function test_phone_confirms_delivery_of_its_own_messages_only(): void
    {
        ['token' => $token] = $this->issue($this->me);
        $this->sendFromFriend('Hello');
        $toMe = Message::sole();
        $mine = Message::factory()->create(['conversation_id' => $this->conversation->id, 'sender_id' => $this->me->id, 'receiver_id' => $this->friend->id]);

        $this->withToken($token)
            ->postJson('/api/device/messages/delivered', ['ids' => [$toMe->id, $mine->id]])
            ->assertOk()
            ->assertJsonPath('updated', 1);

        $this->assertNotNull($toMe->fresh()->delivered_at);
        $this->assertNull($mine->fresh()->delivered_at);
    }

    public function test_reply_from_the_notification_sends_a_message_and_marks_the_chat_read(): void
    {
        ['token' => $token] = $this->issue($this->me);
        $this->sendFromFriend('Kahan ho?');

        $this->withToken($token)
            ->postJson("/api/device/conversations/{$this->conversation->id}/messages", ['message' => "  Raste mein hoon \u{202E} "])
            ->assertCreated();

        $reply = Message::where('sender_id', $this->me->id)->sole();
        $this->assertSame('Raste mein hoon', $reply->message);
        $this->assertSame($this->friend->id, $reply->receiver_id);
        $this->assertNotNull(Message::where('sender_id', $this->friend->id)->sole()->seen_at);
    }

    public function test_reply_is_not_allowed_outside_own_or_blocked_conversations(): void
    {
        ['token' => $token] = $this->issue($this->me);
        $stranger = User::factory()->create();
        $other = Conversation::factory()->between($this->friend, $stranger)->create();

        $this->withToken($token)->postJson("/api/device/conversations/{$other->id}/messages", ['message' => 'hi'])->assertNotFound();
        $this->withToken($token)->postJson("/api/device/conversations/{$other->id}/read")->assertNotFound();

        BlockedUser::create(['user_id' => $this->friend->id, 'blocked_user_id' => $this->me->id]);
        $this->withToken($token)->postJson("/api/device/conversations/{$this->conversation->id}/messages", ['message' => 'hi'])->assertForbidden();

        $this->withToken($token)->postJson("/api/device/conversations/{$this->conversation->id}/messages", ['message' => ''])->assertStatus(403);
        $this->assertSame(0, Message::count());
    }

    public function test_mark_as_read_from_the_notification(): void
    {
        ['token' => $token] = $this->issue($this->me);
        $this->sendFromFriend('Hello');

        $this->withToken($token)
            ->postJson("/api/device/conversations/{$this->conversation->id}/read")
            ->assertOk()
            ->assertJsonCount(1, 'ids');

        $this->assertNotNull(Message::sole()->seen_at);
        $this->assertSame(0, $this->me->unreadNotifications()->count());
    }

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{token: string, device: DeviceToken}
     */
    private function issue(User $user): array
    {
        return app(DeviceService::class)->issue($user, 'android');
    }

    private function withFcmToken(User $user): DeviceToken
    {
        $device = $this->issue($user)['device'];
        $device->forceFill(['fcm_token' => self::FCM_TOKEN, 'fcm_token_hash' => DeviceToken::hashToken(self::FCM_TOKEN)])->save();

        return $device;
    }

    private function sendFromFriend(string $text): void
    {
        $this->actingAs($this->friend)
            ->postJson("/conversations/{$this->conversation->id}/messages", ['message' => $text])
            ->assertCreated();
    }

    private function fakeFirebase(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-access-token', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/one2one-test/messages/1']),
        ]);
    }

    /**
     * Writes a throwaway service-account file with a freshly generated key.
     */
    private function configureFirebase(): void
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $exportOptions = null;
        $key = @openssl_pkey_new($options);

        // Windows PHP builds (XAMPP) need an explicit openssl.cnf.
        foreach ([dirname(PHP_BINARY).'/extras/ssl/openssl.cnf', dirname(PHP_BINARY).'/extras/openssl/openssl.cnf'] as $config) {
            if ($key === false && is_file($config)) {
                $key = @openssl_pkey_new($options + ['config' => $config]);
                $exportOptions = ['config' => $config];
            }
        }

        if ($key === false || ! openssl_pkey_export($key, $pem, null, $exportOptions)) {
            $this->markTestSkipped('OpenSSL cannot generate RSA keys in this environment.');
        }

        $this->credentialsPath = tempnam(sys_get_temp_dir(), 'fcm').'.json';
        file_put_contents($this->credentialsPath, json_encode([
            'type' => 'service_account',
            'project_id' => 'one2one-test',
            'private_key' => $pem,
            'client_email' => 'push@one2one-test.iam.gserviceaccount.com',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));

        config(['chat.push.credentials' => $this->credentialsPath]);
        $this->app->forgetInstance(PushService::class);
    }
}

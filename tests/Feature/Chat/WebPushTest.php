<?php

namespace Tests\Feature\Chat;

use App\Models\Conversation;
use App\Models\User;
use App\Models\WebPushSubscription;
use App\Services\BanService;
use App\Services\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * X3 — browser push notifications (Web Push with VAPID, aes128gcm) and the app manifest.
 */
class WebPushTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123:APA91bTestEndpoint';

    private User $me;

    private User $friend;

    private Conversation $conversation;

    /** @var \OpenSSLAsymmetricKey the browser's key */
    private $browserKey;

    private string $browserPublic;

    private string $authSecret;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        [$this->me, $this->friend] = User::factory()->count(2)->create();
        $this->conversation = Conversation::factory()->between($this->me, $this->friend)->create();

        $this->browserKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC] + $this->opensslOptions());
        $ec = openssl_pkey_get_details($this->browserKey)['ec'];
        $this->browserPublic = "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
        $this->authSecret = random_bytes(16);
    }

    public function test_a_browser_subscribes_and_unsubscribes(): void
    {
        $service = app(WebPushService::class);
        $this->assertTrue($service->available());

        $this->actingAs($this->me)->get('/chat')->assertOk()->assertSee('"webPush":{"publicKey":"'.$service->publicKey().'"}', false);

        $this->postJson('/push/subscriptions', $this->subscription())->assertCreated();
        $this->assertSame(1, $this->me->webPushSubscriptions()->count());

        // The same browser signs in with another account: it moves over.
        $this->actingAs($this->friend)->postJson('/push/subscriptions', $this->subscription())->assertCreated();
        $this->assertSame(0, $this->me->webPushSubscriptions()->count());
        $this->assertSame(1, $this->friend->webPushSubscriptions()->count());

        $this->postJson('/push/subscriptions', ['endpoint' => 'http://insecure.test/x', 'keys' => ['p256dh' => 'short', 'auth' => '!']])
            ->assertJsonValidationErrors(['endpoint', 'keys.p256dh', 'keys.auth']);

        $this->deleteJson('/push/subscriptions', ['endpoint' => self::ENDPOINT])->assertOk();
        $this->assertSame(0, WebPushSubscription::query()->count());
    }

    public function test_new_messages_reach_the_browser_encrypted_and_signed(): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);
        $this->actingAs($this->me)->postJson('/push/subscriptions', $this->subscription())->assertCreated();

        $this->actingAs($this->friend)->postJson("/conversations/{$this->conversation->id}/messages", ['message' => 'Assalam o alaikum 👋'])->assertCreated();

        Http::assertSent(function (Request $request) {
            if ($request->url() !== self::ENDPOINT) {
                return false;
            }

            $this->assertSame('aes128gcm', $request->header('Content-Encoding')[0]);
            $this->assertSame('high', $request->header('Urgency')[0]);
            $this->assertVapid($request->header('Authorization')[0]);

            $payload = json_decode($this->decrypt($request->body()), true);
            $this->assertSame('message', $payload['type']);
            $this->assertSame($this->friend->name, $payload['title']);
            $this->assertSame('Assalam o alaikum 👋', $payload['body']);
            $this->assertSame('conversation-'.$this->conversation->id, $payload['tag']);
            $this->assertStringEndsWith('/chat/'.$this->conversation->id, $payload['url']);

            return true;
        });

        $this->assertNotNull(WebPushSubscription::query()->sole()->last_used_at);
    }

    public function test_reading_the_chat_removes_the_notification_and_gone_browsers_are_forgotten(): void
    {
        $this->actingAs($this->me)->postJson('/push/subscriptions', $this->subscription())->assertCreated();
        $status = 201;
        Http::fake(function () use (&$status) {
            return Http::response('', $status);
        });
        $this->actingAs($this->friend)->postJson("/conversations/{$this->conversation->id}/messages", ['message' => 'Hi'])->assertCreated();

        $this->actingAs($this->me)->postJson("/conversations/{$this->conversation->id}/seen")->assertOk();
        Http::assertSent(fn (Request $request) => $request->url() === self::ENDPOINT
            && json_decode($this->decrypt($request->body()), true) === ['type' => 'read', 'tag' => 'conversation-'.$this->conversation->id]);

        // The browser unsubscribed on its own: the push service answers 410.
        $status = 410;
        $this->actingAs($this->friend)->postJson("/conversations/{$this->conversation->id}/messages", ['message' => 'Still there?'])->assertCreated();
        $this->assertSame(0, WebPushSubscription::query()->count());
    }

    public function test_signing_out_changing_the_password_or_a_ban_stops_notifications(): void
    {
        // Signing out on this browser (same session cookie).
        $session = $this->actingAs($this->me)->postJson('/push/subscriptions', $this->subscription())->assertCreated()->getCookie(config('session.cookie'))->getValue();
        $this->withCookie(config('session.cookie'), $session)->post('/logout');
        $this->assertSame(0, WebPushSubscription::query()->count());

        $this->actingAs($this->me)->postJson('/push/subscriptions', $this->subscription())->assertCreated();
        WebPushSubscription::query()->create(['user_id' => $this->me->id, 'endpoint' => self::ENDPOINT.'-2', 'endpoint_hash' => hash('sha256', self::ENDPOINT.'-2'), 'public_key' => 'x', 'auth_token' => 'y']);
        app(BanService::class)->ban($this->me, User::factory()->admin()->create(), 1, 'Spam');
        $this->assertSame(0, WebPushSubscription::query()->count());
    }

    public function test_call_and_read_payloads(): void
    {
        $service = app(WebPushService::class);

        $call = $service->payloadFor(['type' => 'call', 'call_id' => 9, 'conversation_id' => 4, 'call_type' => 'video', 'caller_name' => 'Ayesha']);
        $this->assertSame(['Incoming video call', 'Ayesha', 'call-9', true], [$call['title'], $call['body'], $call['tag'], $call['require_interaction']]);
        $this->assertSame(['type' => 'close', 'tag' => 'call-9'], $service->payloadFor(['type' => 'call_state', 'call_id' => 9]));
        $this->assertTrue($service->payloadFor(['type' => 'message', 'conversation_id' => 1, 'tone' => 'none'])['silent']);
        $this->assertNull($service->payloadFor(['type' => 'unknown']));
    }

    public function test_the_app_can_be_installed(): void
    {
        $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('display', 'standalone')
            ->assertJsonPath('icons.2.purpose', 'maskable');

        $this->get('/login')->assertSee('rel="manifest"', false);
        $this->assertFileExists(public_path('sw.js'));
        $this->assertFileExists(public_path('offline.html'));
        $this->assertFileExists(public_path('icons/icon-512.png'));
    }

    /* ------------------------------------------------------------------ */

    private function subscription(): array
    {
        $service = app(WebPushService::class);

        return ['endpoint' => self::ENDPOINT, 'keys' => ['p256dh' => $service->base64UrlEncode($this->browserPublic), 'auth' => $service->base64UrlEncode($this->authSecret)]];
    }

    /** What the browser does with the body (RFC 8291). */
    private function decrypt(string $body): string
    {
        $salt = substr($body, 0, 16);
        $idLength = ord($body[20]);
        $serverPublic = substr($body, 21, $idLength);
        $cipher = substr($body, 21 + $idLength);

        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$serverPublic), 64, "\n")."-----END PUBLIC KEY-----\n";
        $secret = openssl_pkey_derive($pem, $this->browserKey, 32);
        $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0".$this->browserPublic.$serverPublic, $this->authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        $plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($cipher, -16));
        $this->assertNotFalse($plain, 'The browser could not decrypt the notification.');
        $this->assertSame("\x02", substr($plain, -1));

        return substr($plain, 0, -1);
    }

    private function assertVapid(string $header): void
    {
        $this->assertMatchesRegularExpression('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)$/', $header);
        preg_match('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k=([\w-]+)$/', $header, $m);
        $service = app(WebPushService::class);

        $claims = json_decode($service->base64UrlDecode($m[2]), true);
        $this->assertSame('https://fcm.googleapis.com', $claims['aud']);
        $this->assertGreaterThan(time(), $claims['exp']);
        $this->assertSame($service->publicKey(), $m[4]);

        // Raw r||s signature back to DER for OpenSSL.
        $raw = $service->base64UrlDecode($m[3]);
        $int = fn (string $v) => (ord(ltrim($v, "\0")[0] ?? "\0") & 0x80 ? "\0".ltrim($v, "\0") : ltrim($v, "\0"));
        [$r, $s] = [$int(substr($raw, 0, 32)), $int(substr($raw, 32))];
        $der = "\x30".chr(4 + strlen($r) + strlen($s))."\x02".chr(strlen($r)).$r."\x02".chr(strlen($s)).$s;
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$service->base64UrlDecode($m[4])), 64, "\n")."-----END PUBLIC KEY-----\n";

        $this->assertSame(1, openssl_verify($m[1].'.'.$m[2], $der, $pem, OPENSSL_ALGO_SHA256));
    }

    private function opensslOptions(): array
    {
        return PHP_OS_FAMILY === 'Windows' && is_file('C:/xampp/php/extras/ssl/openssl.cnf') ? ['config' => 'C:/xampp/php/extras/ssl/openssl.cnf'] : [];
    }
}

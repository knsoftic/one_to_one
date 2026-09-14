<?php

namespace Tests\Feature\Privacy;

use App\Http\Controllers\DeviceController;
use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P9 — Where you're signed in, and signing other devices out.
 */
class ActiveSessionsTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36';

    private const APP = 'Mozilla/5.0 (Linux; Android 14; Pixel 8; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/140.0 Mobile Safari/537.36';

    public function test_sessions_are_listed_without_their_ids_and_can_be_signed_out(): void
    {
        $user = User::factory()->create(['remember_token' => 'old-token']);
        $other = User::factory()->create();
        $phoneHash = hash('sha256', 'phone-token');
        DeviceToken::create(['user_id' => $user->id, 'token_hash' => $phoneHash, 'platform' => 'android']);

        $this->addSession('chrome-session-id', $user, self::CHROME, '39.45.10.1');
        $this->addSession('app-session-id', $user, self::APP, '39.45.10.2', [DeviceController::SESSION_KEY => $phoneHash]);
        $this->addSession('stale-session-id', $user, self::CHROME, '1.1.1.1', [], now()->subDays(3)->getTimestamp());
        $this->addSession('someone-else', $other, self::CHROME, '8.8.8.8');

        $list = $this->actingAs($user)->getJson('/settings/sessions')->assertOk()->json('data');
        $this->assertCount(2, $list);
        $this->assertEqualsCanonicalizing(['Chrome on Windows', config('app.name').' app on Android'], array_column($list, 'device'));
        $this->assertStringNotContainsString('chrome-session-id', json_encode($list));
        $this->actingAs($user)->get('/settings?tab=security')->assertSee("Where you're signed in", false)->assertSee('Chrome on Windows');

        // Signing out the phone ends its session and its notifications, and old "remember me" cookies.
        $app = collect($list)->firstWhere('app', true);
        $this->actingAs($user)->deleteJson('/settings/sessions/'.$app['key'])->assertOk();
        $this->assertFalse(DB::table('sessions')->where('id', 'app-session-id')->exists());
        $this->assertSame(0, DeviceToken::count());
        $this->assertNotSame('old-token', $user->fresh()->remember_token);
        $this->actingAs($user)->deleteJson('/settings/sessions/'.$app['key'])->assertNotFound();

        // Nobody signs out other people's sessions.
        $this->actingAs($other)->deleteJson('/settings/sessions/'.collect($list)->firstWhere('app', false)['key'])->assertNotFound();

        $this->actingAs($user)->deleteJson('/settings/sessions/others')->assertOk()->assertJsonPath('signed_out', 2);
        $this->assertSame(['someone-else'], DB::table('sessions')->pluck('id')->all());
    }

    private function addSession(string $id, User $user, string $agent, string $ip, array $data = [], ?int $lastActivity = null): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => $ip,
            'user_agent' => $agent,
            'payload' => base64_encode(serialize(['_token' => 'x'] + $data)),
            'last_activity' => $lastActivity ?? now()->getTimestamp(),
        ]);
    }
}

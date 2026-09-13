<?php

namespace Tests\Feature;

use App\Console\Commands\ChatDoctor;
use App\Models\User;
use App\Services\DeviceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\ConfiguresFirebase;
use Tests\TestCase;

/**
 * `chat:doctor` and keeping phones on up-to-date connection settings.
 */
class DiagnosticsTest extends TestCase
{
    use ConfiguresFirebase, RefreshDatabase;

    public function test_doctor_reports_missing_realtime_turn_and_scheduler(): void
    {
        config([
            'app.url' => 'https://chat.example.com',
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'key',
            'broadcasting.connections.reverb.secret' => 'secret',
            'broadcasting.connections.reverb.app_id' => '1',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 1,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'chat.calls.turn_urls' => null,
        ]);

        $this->artisan('chat:doctor')
            ->expectsOutputToContain('Broadcasting uses Reverb')
            ->expectsOutputToContain('REVERB_HOST points to this server only. Set REVERB_HOST=chat.example.com')
            ->expectsOutputToContain('TURN server configured')
            ->expectsOutputToContain('Add the cron task from guide step 12')
            ->assertFailed();
    }

    public function test_scheduler_heartbeat_is_recognised(): void
    {
        Cache::put(ChatDoctor::SCHEDULER_HEARTBEAT_KEY, now()->subSeconds(30)->toIso8601String());

        $this->artisan('chat:doctor')->expectsOutputToContain('Scheduler last ran')->run();
    }

    public function test_phones_get_a_new_config_version_when_server_settings_change(): void
    {
        $user = User::factory()->create();
        $devices = app(DeviceService::class);
        $before = $devices->configVersion();

        $this->actingAs($user)->postJson('/devices', ['platform' => 'android'])->assertJsonPath('config_version', $before);

        // Firebase switched on after the phone registered.
        $this->configureFirebase();
        $afterPush = $devices->configVersion();
        $this->assertNotSame($before, $afterPush);

        // WebSocket address fixed.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'key',
            'broadcasting.connections.reverb.options.host' => 'chat.example.com',
            'broadcasting.connections.reverb.options.port' => 443,
            'broadcasting.connections.reverb.options.scheme' => 'https',
        ]);
        $this->assertNotSame($afterPush, $devices->configVersion());
    }

    public function test_pages_share_the_config_version_with_the_app(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get('/settings')->assertOk()->getContent();

        $this->assertStringContainsString('"configVersion":"'.app(DeviceService::class)->configVersion().'"', $html);
    }
}

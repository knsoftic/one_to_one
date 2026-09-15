<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use App\Models\AppSetting;
use App\Models\User;
use App\Services\AppUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * X4 — "Update available": publishing Android app versions and the web app version.
 */
class AppReleaseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('local');

        $this->admin = User::factory()->admin()->create();
    }

    public function test_admin_publishes_a_version_with_its_apk(): void
    {
        $this->actingAs($this->admin)->get('/admin/settings')->assertOk()->assertSee('Android app')->assertSee('Latest version code');

        $this->put('/admin/app-release', [
            'latest_code' => 3,
            'latest_name' => '1.2',
            'min_code' => 2,
            'notes' => 'Own tone for each chat',
            'apk' => UploadedFile::fake()->create('app-release.apk', 2048, 'application/vnd.android.package-archive'),
        ])->assertRedirect(route('admin.settings').'#android')->assertSessionHasNoErrors();

        $update = app(AppUpdateService::class)->android();
        $this->assertSame(['latest_code' => 3, 'latest_name' => '1.2', 'min_code' => 2, 'notes' => 'Own tone for each chat', 'url' => route('app.download.android')], $update);
        $this->assertStringContainsString('Published Android app 1.2', AdminAuditLog::query()->sole()->description);

        // Anyone can download it (share the link to install the app).
        auth()->logout();
        $this->get('/download/android')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.android.package-archive')
            ->assertDownload('one2one-chat-1.2.apk');

        // The app reads it from the page config.
        $this->get('/login')->assertSee('"appUpdate":{"android":{"latest_code":3', false);

        // A store link wins over the APK; removing the APK deletes the file.
        $old = AppSetting::get('android_apk_path');
        $this->actingAs($this->admin)->put('/admin/app-release', ['latest_code' => 3, 'latest_name' => '1.2', 'download_url' => 'https://play.google.com/store/apps/details?id=com.hunario.chat', 'remove_apk' => 1])->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($old);
        $this->get('/download/android')->assertRedirect('https://play.google.com/store/apps/details?id=com.hunario.chat');
    }

    public function test_validation_and_turning_the_prompt_off(): void
    {
        $this->actingAs($this->admin)->put('/admin/app-release', ['latest_code' => 2, 'min_code' => 5])->assertSessionHasErrorsIn('release', ['min_code']);
        $this->put('/admin/app-release', ['latest_code' => 2, 'apk' => UploadedFile::fake()->create('virus.exe', 10)])->assertSessionHasErrorsIn('release', ['apk']);
        $this->put('/admin/app-release', ['download_url' => 'https://example.com/app'])->assertSessionHasErrorsIn('release', ['latest_code']);
        $this->put('/admin/app-release', ['latest_code' => 2, 'download_url' => 'http://insecure.example/app'])->assertSessionHasErrorsIn('release', ['download_url']);

        $this->put('/admin/app-release', ['latest_code' => ''])->assertSessionHasNoErrors();
        $this->assertNull(app(AppUpdateService::class)->android());
        $this->get('/download/android')->assertNotFound();

        $this->actingAs(User::factory()->create())->put('/admin/app-release', ['latest_code' => 9])->assertForbidden();
    }

    public function test_responses_carry_the_web_app_version(): void
    {
        $version = app(AppUpdateService::class)->webVersion();
        $this->assertNotSame('', $version);

        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/conversations')->assertHeader('X-App-Version', $version);
        $this->get('/chat')->assertSee('"version":"'.$version.'"', false);
    }
}

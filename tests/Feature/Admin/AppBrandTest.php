<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Services\BrandService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Admin → App settings → App name & icon, and `php artisan app:android-brand`.
 */
class AppBrandTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->admin = User::factory()->admin()->create();
    }

    /** A see-through logo: transparent corners, a solid circle in the middle. */
    private function logo(int $size = 600): UploadedFile
    {
        $image = imagecreatetruecolor($size, $size);
        imagealphablending($image, false);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledellipse($image, intdiv($size, 2), intdiv($size, 2), intdiv($size, 2), intdiv($size, 2), imagecolorallocatealpha($image, 255, 255, 255, 0));
        imagesavealpha($image, true);
        $path = tempnam(sys_get_temp_dir(), 'logo').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return new UploadedFile($path, 'logo.png', 'image/png', null, true);
    }

    private function save(array $data): TestResponse
    {
        return $this->actingAs($this->admin)->put(route('admin.brand.update'), $data + ['name' => 'Hunario', 'color' => '#112233']);
    }

    public function test_the_app_name_changes_everywhere(): void
    {
        $this->actingAs($this->admin)->get(route('admin.settings'))->assertOk()->assertSee('App name &amp; icon', false)->assertSee('data-brand-form', false);

        $this->save(['name' => 'Hunario'])->assertRedirect(route('admin.settings').'#brand')->assertSessionHasNoErrors();

        $this->assertSame('Hunario', config('app.name'));
        $this->assertSame('Hunario', config('mail.from.name'));
        $this->assertDatabaseHas(AdminAuditLog::class, ['action' => 'app.brand_updated', 'admin_id' => $this->admin->id]);

        auth()->logout();
        $this->get(route('login'))->assertOk()->assertSee('<title>Sign in · Hunario</title>', false);
        $this->get(route('manifest'))->assertOk()->assertJsonPath('name', 'Hunario')->assertJsonPath('icons.0.src', asset('icons/icon-192.png'));
        $this->getJson(route('app.brand'))->assertOk()->assertExactJson(['name' => 'Hunario', 'color' => '#112233', 'icon' => null]);

        // Saving the .env name again goes back to following .env.
        $default = app(BrandService::class)->defaultName();
        $this->save(['name' => $default]);
        $this->assertNull(app(BrandService::class)->settings()['name']);
        $this->assertSame($default, config('app.name'));
    }

    public function test_an_uploaded_icon_is_saved_in_every_size_the_web_and_android_need(): void
    {
        $this->save(['icon' => UploadedFile::fake()->image('icon.png', 1024, 1024)])->assertSessionHasNoErrors();

        $brand = app(BrandService::class)->settings();
        $this->assertTrue($brand['gd']);
        $dir = 'brand/'.$brand['icon'];
        foreach ([32, 96, 180, 192, 512] as $size) {
            Storage::disk('public')->assertExists("{$dir}/icon-{$size}.png");
            $this->assertSame([$size, $size], array_slice(getimagesize(Storage::disk('public')->path("{$dir}/icon-{$size}.png")), 0, 2));
        }
        Storage::disk('public')->assertExists("{$dir}/maskable-512.png");
        foreach (['launcher' => 192, 'round' => 192, 'foreground' => 432, 'splash' => 1152] as $kind => $size) {
            $this->assertSame($size, getimagesize(Storage::disk('public')->path("{$dir}/android-{$kind}-xxxhdpi.png"))[0]);
        }
        $this->assertSame(48, getimagesize(Storage::disk('public')->path("{$dir}/android-launcher-mdpi.png"))[0]);

        auth()->logout();
        $this->get(route('login'))->assertOk()
            ->assertSee('brand-mark has-image', false)
            ->assertSee('/storage/'.$dir.'/icon-96.png', false)
            ->assertSee('<link rel="apple-touch-icon" href="'.Storage::disk('public')->url($dir.'/icon-180.png').'">', false)
            ->assertSee('type="image/png"', false);
        $this->get(route('manifest'))->assertJsonPath('icons.0.src', Storage::disk('public')->url($dir.'/icon-192.png'))
            ->assertJsonPath('icons.2.src', Storage::disk('public')->url($dir.'/maskable-512.png'));
        $this->getJson(route('app.brand'))->assertJsonPath('icon.android.xxxhdpi.foreground', Storage::disk('public')->url($dir.'/android-foreground-xxxhdpi.png'))
            ->assertJsonPath('icon.version', $brand['icon']);
    }

    public function test_a_see_through_logo_sits_on_the_background_colour_and_a_new_colour_remakes_the_icons(): void
    {
        $this->save(['icon' => $this->logo(), 'color' => '#112233'])->assertSessionHasNoErrors();
        $first = app(BrandService::class)->settings()['icon'];

        $maskable = imagecreatefrompng(Storage::disk('public')->path("brand/{$first}/maskable-512.png"));
        $this->assertSame(['red' => 0x11, 'green' => 0x22, 'blue' => 0x33, 'alpha' => 0], imagecolorsforindex($maskable, imagecolorat($maskable, 2, 2)));
        // The favicon keeps its see-through corners.
        $favicon = imagecreatefrompng(Storage::disk('public')->path("brand/{$first}/icon-32.png"));
        $this->assertSame(127, imagecolorsforindex($favicon, imagecolorat($favicon, 0, 0))['alpha']);

        $this->save(['color' => '#AA0000'])->assertSessionHasNoErrors();
        $second = app(BrandService::class)->settings()['icon'];
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing("brand/{$first}/icon-192.png");
        $maskable = imagecreatefrompng(Storage::disk('public')->path("brand/{$second}/maskable-512.png"));
        $this->assertSame(0xAA, imagecolorsforindex($maskable, imagecolorat($maskable, 2, 2))['red']);
    }

    public function test_the_built_in_icon_can_come_back(): void
    {
        $this->save(['icon' => UploadedFile::fake()->image('icon.png', 800, 800)]);
        $version = app(BrandService::class)->settings()['icon'];

        $this->save(['remove_icon' => '1'])->assertSessionHasNoErrors();

        $this->assertFalse(app(BrandService::class)->hasIcon());
        Storage::disk('public')->assertMissing("brand/{$version}/icon-192.png");
        $this->get(route('manifest'))->assertJsonPath('icons.0.src', asset('icons/icon-192.png'));
    }

    public function test_it_checks_the_name_colour_and_picture(): void
    {
        $this->save(['name' => ''])->assertSessionHasErrors('name', null, 'brand');
        $this->save(['name' => str_repeat('a', 31)])->assertSessionHasErrors('name', null, 'brand');
        $this->save(['name' => 'Chat <b>'])->assertSessionHasErrors('name', null, 'brand');
        $this->save(['color' => 'red'])->assertSessionHasErrors('color', null, 'brand');
        $this->save(['icon' => UploadedFile::fake()->image('small.png', 200, 200)])->assertSessionHasErrors('icon', null, 'brand');
        $this->save(['icon' => UploadedFile::fake()->create('icon.svg', 10, 'image/svg+xml')])->assertSessionHasErrors('icon', null, 'brand');

        $this->assertNull(app(BrandService::class)->settings()['name']);
    }

    public function test_only_admins_can_change_it(): void
    {
        $this->put(route('admin.brand.update'), ['name' => 'X', 'color' => '#000000'])->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->put(route('admin.brand.update'), ['name' => 'X', 'color' => '#000000'])->assertForbidden();
        $this->assertNull(app(BrandService::class)->settings()['name']);
    }

    /** A throwaway copy of the Android project's name and icon files. */
    private function androidProject(): string
    {
        $mobile = storage_path('framework/testing/mobile-'.uniqid());
        $res = base_path('mobile/android/app/src/main/res');
        foreach (['values/strings.xml', 'values/ic_launcher_background.xml', 'values/styles.xml', 'mipmap-anydpi-v26/ic_launcher.xml'] as $file) {
            File::ensureDirectoryExists(dirname("{$mobile}/android/app/src/main/res/{$file}"));
            File::copy("{$res}/{$file}", "{$mobile}/android/app/src/main/res/{$file}");
        }
        File::copy(base_path('mobile/capacitor.config.json'), "{$mobile}/capacitor.config.json");

        return $mobile;
    }

    public function test_the_android_app_gets_the_name_and_icon_before_it_is_built(): void
    {
        $this->save(['name' => "Hunario's", 'color' => '#0F766E', 'icon' => UploadedFile::fake()->image('icon.png', 1024, 1024)]);
        $mobile = $this->androidProject();
        $res = "{$mobile}/android/app/src/main/res";

        try {
            $this->artisan('app:android-brand', ['--mobile' => $mobile])->expectsOutputToContain("Name: Hunario's")->assertSuccessful();

            $strings = File::get("{$res}/values/strings.xml");
            $this->assertStringContainsString('<string name="app_name">Hunario\\\'s</string>', $strings);
            $this->assertStringContainsString('<string name="share_label">Send with Hunario\\\'s</string>', $strings);
            $this->assertStringContainsString('Keeps Hunario\\\'s connected', $strings);
            $this->assertStringNotContainsString('One2One', $strings);
            $this->assertSame("Hunario's", json_decode(File::get("{$mobile}/capacitor.config.json"), true)['appName']);

            $this->assertSame(192, getimagesize("{$res}/mipmap-xxxhdpi/ic_launcher.png")[0]);
            $this->assertFileExists("{$res}/mipmap-mdpi/ic_launcher_round.png");
            $this->assertFileExists("{$res}/mipmap-hdpi/ic_splash.png");
            $this->assertStringContainsString('@mipmap/ic_launcher_foreground', File::get("{$res}/mipmap-anydpi-v26/ic_launcher.xml"));
            $this->assertStringContainsString('<color name="ic_launcher_background">#0F766E</color>', File::get("{$res}/values/ic_launcher_background.xml"));
            $this->assertStringContainsString('<item name="windowSplashScreenAnimatedIcon">@mipmap/ic_splash</item>', File::get("{$res}/values/styles.xml"));
        } finally {
            File::deleteDirectory($mobile);
        }
    }

    public function test_the_android_app_can_take_them_from_the_live_server(): void
    {
        $this->save(['name' => 'Hunario', 'icon' => UploadedFile::fake()->image('icon.png', 1024, 1024)]);
        $payload = app(BrandService::class)->publicPayload();
        $png = File::get(Storage::disk('public')->path('brand/'.$payload['icon']['version'].'/android-launcher-mdpi.png'));
        $mobile = $this->androidProject();

        Http::fake([
            'chat.example.com/app-brand.json' => Http::response($payload),
            '*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);

        try {
            $this->artisan('app:android-brand', ['--mobile' => $mobile, '--url' => 'https://chat.example.com/'])->assertSuccessful();
            $this->assertStringContainsString('<string name="app_name">Hunario</string>', File::get("{$mobile}/android/app/src/main/res/values/strings.xml"));
            $this->assertFileExists("{$mobile}/android/app/src/main/res/mipmap-xxhdpi/ic_launcher_foreground.png");

            Http::fake(['*' => Http::response('<html>', 200)]);
            $this->artisan('app:android-brand', ['--mobile' => $mobile, '--url' => 'https://other.example.com'])->assertFailed();
            $this->artisan('app:android-brand', ['--mobile' => $mobile, '--url' => 'chat.example.com'])->expectsOutputToContain('full server address')->assertFailed();
        } finally {
            File::deleteDirectory($mobile);
        }
    }
}

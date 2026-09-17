<?php

namespace App\Console\Commands;

use App\Services\BrandService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Copies the app name and icon from Admin → App settings into the Android app, before an APK
 * is built (a phone's home-screen name and icon come from the installed app):
 *
 *   php artisan app:android-brand --url=https://chat.hunario.com   (the live server's settings)
 *   php artisan app:android-brand                                  (this installation's settings)
 */
class AndroidBrand extends Command
{
    protected $signature = 'app:android-brand
        {--url= : Read the name and icon from this server instead of this installation}
        {--mobile= : The Capacitor project folder (default: mobile/)}';

    protected $description = 'Put the app name and icon from Admin → App settings into the Android app';

    /** Texts in strings.xml that carry the app name (only these change). */
    private const NAMED_STRINGS = ['app_name', 'title_activity_main', 'share_label', 'channel_messages_custom_description', 'channel_connection_description'];

    public function handle(BrandService $brand): int
    {
        $mobile = rtrim((string) ($this->option('mobile') ?: base_path('mobile')), '/\\');
        $res = $mobile.'/android/app/src/main/res';
        if (! is_file($res.'/values/strings.xml')) {
            $this->error("No Android project found in {$mobile}.");

            return self::FAILURE;
        }

        try {
            [$name, $color, $icons] = $this->option('url') ? $this->fromServer((string) $this->option('url')) : $this->fromHere($brand);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->rename($mobile, $res, $name);
        $this->line("Name: <info>{$name}</info>");

        if ($icons === null) {
            $this->line('Icon: no icon uploaded, the built-in icon stays.');
            if (str_contains((string) @file_get_contents($res.'/mipmap-anydpi-v26/ic_launcher.xml'), 'app:android-brand')) {
                $this->warn('The Android app still has an icon uploaded before. To go back to the built-in one: git checkout -- mobile/android/app/src/main/res');
            }
        } else {
            $this->installIcons($res, $icons, $color);
            $this->line("Icon: <info>installed</info> (background {$color}).");
        }

        $this->newLine();
        $this->line('Next: raise versionCode / versionName in mobile/android/app/build.gradle, build the APK and publish it in Admin → App settings → Android app.');

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, array<string, string>>|null} name, colour, file per density and kind
     */
    private function fromHere(BrandService $brand): array
    {
        $settings = $brand->settings();
        if ($settings['icon'] !== null && ! $settings['gd']) {
            throw new RuntimeException('The icon was saved without PHP\'s gd extension, so there are no Android sizes. Enable gd and upload the icon again.');
        }

        $icons = null;
        if ($settings['icon'] !== null) {
            foreach (array_keys(BrandService::ANDROID_DENSITIES) as $density) {
                foreach (['launcher', 'round', 'foreground', 'splash'] as $kind) {
                    $path = $brand->androidIconPath($kind, $density);
                    if ($path === null) {
                        throw new RuntimeException("The {$kind} icon for {$density} is missing. Upload the icon again in Admin → App settings.");
                    }
                    $icons[$density][$kind] = $path;
                }
            }
        }

        return [$brand->name(), $settings['color'], $icons];
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, array<string, string>>|null}
     */
    private function fromServer(string $url): array
    {
        $url = rtrim($url, '/');
        if (! preg_match('#^https?://#', $url)) {
            throw new RuntimeException('Use the full server address, like https://chat.hunario.com');
        }

        $payload = Http::acceptJson()->timeout(20)->get($url.'/app-brand.json')->throw()->json();
        $name = trim((string) ($payload['name'] ?? ''));
        $color = (string) ($payload['color'] ?? BrandService::DEFAULT_COLOR);
        if ($name === '' || ! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            throw new RuntimeException("{$url}/app-brand.json did not return an app name and colour.");
        }

        $icons = null;
        if (! empty($payload['icon'])) {
            $android = $payload['icon']['android'] ?? null;
            if (! is_array($android)) {
                throw new RuntimeException('The server has an icon but no Android sizes (PHP gd extension missing there). Enable gd and upload the icon again.');
            }
            $temp = storage_path('app/android-brand');
            File::ensureDirectoryExists($temp);
            foreach (array_keys(BrandService::ANDROID_DENSITIES) as $density) {
                foreach (['launcher', 'round', 'foreground', 'splash'] as $kind) {
                    $file = $android[$density][$kind] ?? null;
                    if (is_string($file) && str_starts_with($file, '/') && ! str_starts_with($file, '//')) {
                        $file = $url.$file;
                    }
                    if (! is_string($file) || ! preg_match('#^https?://#', $file)) {
                        throw new RuntimeException("The server did not list the {$kind} icon for {$density}.");
                    }
                    $body = Http::timeout(30)->get($file)->throw()->body();
                    if (! str_starts_with($body, "\x89PNG")) {
                        throw new RuntimeException("{$file} is not a PNG picture.");
                    }
                    $path = "{$temp}/{$kind}-{$density}.png";
                    File::put($path, $body);
                    $icons[$density][$kind] = $path;
                }
            }
        }

        return [$name, $color, $icons];
    }

    private function rename(string $mobile, string $res, string $name): void
    {
        $stringsPath = $res.'/values/strings.xml';
        $xml = File::get($stringsPath);
        $old = preg_match('#<string name="app_name">(.*?)</string>#', $xml, $match) ? html_entity_decode($match[1], ENT_QUOTES | ENT_XML1) : null;
        $escaped = $this->xmlText($name);

        // The launcher name and share menu entry are set; other texts keep their words around the name.
        $set = ['app_name' => $escaped, 'title_activity_main' => $escaped, 'share_label' => 'Send with '.$escaped];
        foreach (self::NAMED_STRINGS as $key) {
            $xml = (string) preg_replace_callback('#(<string name="'.$key.'">)(.*?)(</string>)#', fn (array $m) => $m[1]
                .($set[$key] ?? ($old === null ? $m[2] : str_replace($this->xmlText($old), $escaped, $m[2])))
                .$m[3], $xml);
        }
        File::put($stringsPath, $xml);

        // Capacitor's own copy of the name (and the copy inside the Android project, when it exists).
        foreach ([$mobile.'/capacitor.config.json', $mobile.'/android/app/src/main/assets/capacitor.config.json'] as $config) {
            if (! is_file($config)) {
                continue;
            }
            $text = File::get($config);
            $updated = preg_replace_callback('/("appName"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/', fn (array $m) => $m[1].json_encode($name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $text, 1);
            File::put($config, (string) $updated);
        }
    }

    /**
     * @param  array<string, array<string, string>>  $icons
     */
    private function installIcons(string $res, array $icons, string $color): void
    {
        foreach ($icons as $density => $files) {
            File::ensureDirectoryExists("{$res}/mipmap-{$density}");
            File::copy($files['launcher'], "{$res}/mipmap-{$density}/ic_launcher.png");
            File::copy($files['round'], "{$res}/mipmap-{$density}/ic_launcher_round.png");
            File::copy($files['foreground'], "{$res}/mipmap-{$density}/ic_launcher_foreground.png");
            File::copy($files['splash'], "{$res}/mipmap-{$density}/ic_splash.png");
        }

        // Android 8+ adaptive icon: the uploaded picture on the background colour.
        $adaptive = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<!-- Made by `php artisan app:android-brand` from Admin → App settings → App name & icon. -->
<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">
    <background android:drawable="@color/ic_launcher_background"/>
    <foreground android:drawable="@mipmap/ic_launcher_foreground"/>
</adaptive-icon>

XML;
        File::ensureDirectoryExists("{$res}/mipmap-anydpi-v26");
        File::put("{$res}/mipmap-anydpi-v26/ic_launcher.xml", $adaptive);
        File::put("{$res}/mipmap-anydpi-v26/ic_launcher_round.xml", $adaptive);

        $colors = "{$res}/values/ic_launcher_background.xml";
        File::put($colors, (string) preg_replace('#(<color name="ic_launcher_background">)[^<]*(</color>)#', '${1}'.strtoupper($color).'${2}', File::get($colors)));

        // Splash screen shows the same icon.
        $styles = "{$res}/values/styles.xml";
        File::put($styles, (string) preg_replace('#(<item name="windowSplashScreenAnimatedIcon">)[^<]*(</item>)#', '${1}@mipmap/ic_splash${2}', File::get($styles)));
    }

    private function xmlText(string $text): string
    {
        return str_replace(["'", '"'], ["\\'", '\\"'], htmlspecialchars($text, ENT_NOQUOTES | ENT_XML1));
    }
}

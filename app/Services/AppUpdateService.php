<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * X4 — "Update available": the newest Android app (set in the admin panel, with its APK
 * or a store link), and the version of the web app so open tabs know a new one is live.
 */
class AppUpdateService
{
    public const DISK = 'local';

    private const DIRECTORY = 'downloads/android';

    /**
     * What the Android app compares its own version with (null = nothing published).
     *
     * @return array{latest_code: int, latest_name: string, min_code: ?int, notes: ?string, url: ?string}|null
     */
    public function android(): ?array
    {
        $code = (int) AppSetting::get('android_latest_code');
        if ($code <= 0) {
            return null;
        }

        $min = (int) AppSetting::get('android_min_code');

        return [
            'latest_code' => $code,
            'latest_name' => (string) (AppSetting::get('android_latest_name') ?: $code),
            'min_code' => $min > 0 ? min($min, $code) : null,
            'notes' => AppSetting::get('android_notes') ?: null,
            'url' => $this->downloadUrl(),
        ];
    }

    /** A store link wins; otherwise the uploaded APK. */
    public function downloadUrl(): ?string
    {
        $link = AppSetting::get('android_download_url');
        if (filled($link)) {
            return (string) $link;
        }

        return $this->apkPath() ? route('app.download.android') : null;
    }

    public function apkPath(): ?string
    {
        $path = AppSetting::get('android_apk_path');
        $disk = Storage::disk(self::DISK);

        return $path && $disk->exists($path) ? $disk->path($path) : null;
    }

    public function apkSize(): ?int
    {
        $path = $this->apkPath();

        return $path ? (int) filesize($path) : null;
    }

    /**
     * @param  array{latest_code?: ?int, latest_name?: ?string, min_code?: ?int, notes?: ?string, download_url?: ?string}  $values
     */
    public function update(array $values, ?UploadedFile $apk = null, bool $removeApk = false): void
    {
        $settings = [
            'android_latest_code' => isset($values['latest_code']) ? (int) $values['latest_code'] : null,
            'android_latest_name' => filled($values['latest_name'] ?? null) ? trim((string) $values['latest_name']) : null,
            'android_min_code' => isset($values['min_code']) ? (int) $values['min_code'] : null,
            'android_notes' => filled($values['notes'] ?? null) ? trim((string) $values['notes']) : null,
            'android_download_url' => filled($values['download_url'] ?? null) ? trim((string) $values['download_url']) : null,
        ];

        $previous = AppSetting::get('android_apk_path');
        if ($apk) {
            $name = Str::slug(config('app.name')).'-'.Str::slug($settings['android_latest_name'] ?? (string) $settings['android_latest_code'] ?: 'latest').'-'.Str::random(6).'.apk';
            $settings['android_apk_path'] = $apk->storeAs(self::DIRECTORY, $name, self::DISK);
        } elseif ($removeApk) {
            $settings['android_apk_path'] = null;
        }

        AppSetting::put($settings);

        if (($apk || $removeApk) && $previous && $previous !== ($settings['android_apk_path'] ?? null)) {
            Storage::disk(self::DISK)->delete($previous);
        }
    }

    public function downloadName(): string
    {
        $version = AppSetting::get('android_latest_name');

        return Str::slug(config('app.name'), '-').($version ? '-'.$version : '').'.apk';
    }

    /**
     * Changes with every build of the web app (new deploy = new value).
     */
    public function webVersion(): string
    {
        $manifest = public_path('build/manifest.json');
        if (! is_file($manifest)) {
            return 'dev';
        }

        $mtime = (int) filemtime($manifest);

        return Cache::rememberForever("app-version:{$mtime}", fn () => substr(hash_file('sha256', $manifest), 0, 12));
    }
}

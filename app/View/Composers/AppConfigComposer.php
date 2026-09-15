<?php

namespace App\View\Composers;

use App\Models\User;
use App\Services\AppUpdateService;
use App\Services\DeviceService;
use App\Services\WallpaperService;
use App\Services\WebPushService;
use App\Support\ChatPreferences;
use App\Support\Locales;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * Shares a small, non-sensitive configuration object with the frontend.
 * Rendered as JSON (escaped by @json) inside the base layout.
 */
class AppConfigComposer
{
    public function compose(View $view): void
    {
        /** @var User|null $user */
        $user = auth()->user();

        $routes = collect([
            'preferences' => 'profile.preferences',
            'wallpaper' => 'settings.wallpaper.update',
            'storageSummary' => 'storage.summary',
            'storageFiles' => 'storage.files',
            'storageDelete' => 'storage.delete',
            'backupShow' => 'backups.show',
            'backupStore' => 'backups.store',
            'webPushStore' => 'web-push.store',
            'webPushDestroy' => 'web-push.destroy',
            'logout' => 'logout',
            'settings' => 'profile.edit',
            'chat' => 'chat.index',
            'devices' => 'devices.store',
        ])->filter(fn ($name) => Route::has($name))->map(fn ($name) => route($name))
            // Lightweight endpoint the connection bar pings to detect recovery.
            ->put('health', url('/up'));

        $view->with('appConfig', [
            'name' => config('app.name'),
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'avatar_url' => $user->avatar_url,
                'initials' => $user->initials,
                'avatar_hue' => $user->avatar_hue,
                'theme' => $user->theme,
                'notifications_enabled' => $user->notifications_enabled,
                'notification_sound' => $user->notification_sound,
                // Phase 8 — how chats look and alert.
                'wallpaper' => WallpaperService::payload($user->wallpaper, $user->wallpaper_path, 'settings.wallpaper.show') + ['dim' => (int) $user->wallpaper_dim],
                'font_size' => $user->font_size,
                'notification_tone' => $user->notification_tone,
                'notification_vibrate' => $user->notification_vibrate,
                'auto_download' => ChatPreferences::autoDownload($user->auto_download),
                'is_admin' => $user->isAdmin(),
            ] : null,
            'routes' => $routes,
            // The Android app registers again when this changes (see resources/js/native/app.js).
            'mobile' => $user && Route::has('devices.store') ? ['configVersion' => app(DeviceService::class)->configVersion()] : null,
            // X4: the web app's build (open tabs offer a reload after a deploy) and the newest Android app.
            'version' => app(AppUpdateService::class)->webVersion(),
            // X1: app language; Urdu text is swapped in by the browser.
            'locale' => app()->getLocale(),
            'dir' => Locales::direction(),
            'appUpdate' => ['android' => app(AppUpdateService::class)->android()],
            // Browser push (X3): the key browsers subscribe with.
            'webPush' => $user ? ['publicKey' => app(WebPushService::class)->publicKey()] : null,
            'flash' => array_filter([
                'status' => session('toast'),
                'error' => session('toast_error'),
            ]),
        ]);
    }
}

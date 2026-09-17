<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Services\AppUpdateService;
use App\Services\BrandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Public pieces of the app shell: the web app manifest (X3) and the Android app download (X4).
 */
class AppShellController extends Controller
{
    public function manifest(BrandService $brand): JsonResponse
    {
        $icon = $brand->iconUrl(192);

        return response()->json([
            'name' => config('app.name'),
            'short_name' => config('app.name'),
            'description' => 'Chat, call and share with the people you know.',
            'id' => '/chat',
            'start_url' => '/chat?source=pwa',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#f4f3fb',
            'theme_color' => '#4338ca',
            'categories' => ['social', 'communication'],
            // X2: the installed web app appears in the phone's Share menu for text and links.
            'share_target' => ['action' => '/chat', 'method' => 'GET', 'params' => ['title' => 'share_title', 'text' => 'share_text', 'url' => 'share_url']],
            'icons' => [
                ['src' => $icon, 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => $brand->iconUrl(512), 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => $brand->iconUrl('maskable'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => [
                ['name' => 'Chats', 'url' => '/chat', 'icons' => [['src' => $icon, 'sizes' => '192x192']]],
                ['name' => 'Settings', 'url' => '/settings', 'icons' => [['src' => $icon, 'sizes' => '192x192']]],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600'], JSON_UNESCAPED_SLASHES);
    }

    /** The store link when there is one, otherwise the APK uploaded in the admin panel. */
    public function downloadAndroid(AppUpdateService $updates): RedirectResponse|BinaryFileResponse
    {
        $link = AppSetting::get('android_download_url');
        if (filled($link)) {
            return redirect()->away((string) $link);
        }

        $path = $updates->apkPath();
        abort_if($path === null, 404);

        return response()->download($path, $updates->downloadName(), [
            'Content-Type' => 'application/vnd.android.package-archive',
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

<?php

namespace App\View\Composers;

use App\Models\User;
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
            'logout' => 'logout',
            'settings' => 'profile.edit',
            'chat' => 'chat.index',
        ])->filter(fn ($name) => Route::has($name))->map(fn ($name) => route($name));

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
                'is_admin' => $user->isAdmin(),
            ] : null,
            'routes' => $routes,
            'flash' => array_filter([
                'status' => session('toast'),
                'error' => session('toast_error'),
            ]),
        ]);
    }
}

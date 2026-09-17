<?php

namespace App\Providers;

use App\Broadcasting\ResilientBroadcastManager;
use App\Services\AppConfigService;
use App\Services\BrandService;
use App\Services\ChatLockService;
use App\Services\ConversationTypes;
use App\Services\PrivacyService;
use App\Services\PushService;
use App\Services\ReadReceiptService;
use App\View\Composers\AppConfigComposer;
use App\View\Composers\ChatConfigComposer;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->extend(BroadcastManager::class, fn (BroadcastManager $manager, $app) => new ResilientBroadcastManager($app));

        // Reads the Firebase service-account file once per request.
        $this->app->singleton(PushService::class);

        // SMS, email, GIF and call settings saved in the admin panel (MySQL).
        $this->app->singleton(AppConfigService::class);

        // App name and icon from Admin → App settings (remembers the .env name).
        $this->app->singleton(BrandService::class);

        // Remembers lock checks for one request (C9).
        $this->app->scoped(ChatLockService::class);

        // Remembers which conversations are channels for one request (G11).
        $this->app->scoped(ConversationTypes::class);

        // Remember privacy and read receipt checks for one request (Phase 6).
        $this->app->scoped(PrivacyService::class);
        $this->app->scoped(ReadReceiptService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        JsonResource::withoutWrapping();

        // Settings saved in the admin panel win over .env; queued jobs pick up changes too.
        $this->app->make(AppConfigService::class)->apply();
        $this->app->make(BrandService::class)->apply();
        Queue::before(function () {
            $this->app->make(AppConfigService::class)->apply();
            $this->app->make(BrandService::class)->apply();
        });

        // Per-request memory (scoped services) never outlives its request.
        Event::listen(RequestHandled::class, fn () => $this->app->forgetScopedInstances());

        View::composer('components.layouts.base', AppConfigComposer::class);
        View::composer('chat.index', ChatConfigComposer::class);

        // Surface N+1 queries during development without breaking pages.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) {
            Log::warning(sprintf('Lazy loading [%s] on model [%s].', $relation, $model::class));
        });

        Password::defaults(function () {
            // Easy to type on a phone: 8+ characters with letters and a number (no capital letter needed).
            $rule = Password::min(8)->max(255)->letters()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(3)->by('reset:'.$request->ip()),
            Limit::perHour(10)->by('reset-email:'.mb_strtolower((string) $request->input('email'))),
        ]);

        // SMS codes cost money: a few requests a minute per network address (Phase 7).
        RateLimiter::for('otp', fn (Request $request) => Limit::perMinute(6)->by('otp:'.($request->user()?->id ?? $request->ip())));

        // Building the account report reads a lot: a few per hour (A5).
        RateLimiter::for('account-export', fn (Request $request) => Limit::perHour(10)->by('export:'.($request->user()?->id ?? $request->ip())));

        RateLimiter::for('chat-send', fn (Request $request) => Limit::perMinute(60)->by('send:'.($request->user()?->id ?? $request->ip())));

        // Secret code attempts for locked chats (C9).
        RateLimiter::for('chat-lock', fn (Request $request) => Limit::perMinute(5)->by($request->route()?->getName().':'.($request->user()?->id ?? $request->ip())));

        RateLimiter::for('chat-actions', fn (Request $request) => Limit::perMinute(120)->by('actions:'.($request->user()?->id ?? $request->ip())));

        // Mobile app background service (polling + channel auth on reconnect).
        RateLimiter::for('device-api', fn (Request $request) => Limit::perMinute(60)->by('device:'.($request->attributes->get('device')?->id ?? $request->ip())));

        RateLimiter::for('chat-search', fn (Request $request) => Limit::perMinute(60)->by('search:'.($request->user()?->id ?? $request->ip())));

        // Export chat (D7) and backups (D8) build files on the server.
        RateLimiter::for('chat-export', fn (Request $request) => Limit::perMinute(6)->by('export:'.($request->user()?->id ?? $request->ip())));

        // Each preview may contact an outside website: keep it modest.
        RateLimiter::for('link-preview', fn (Request $request) => Limit::perMinute(30)->by('link-preview:'.($request->user()?->id ?? $request->ip())));

        // A sharing phone reports its position every ~15 s.
        RateLimiter::for('live-location', fn (Request $request) => Limit::perMinute(12)->by('live-location:'.($request->user()?->id ?? $request->ip())));

        RateLimiter::for('chat-typing', fn (Request $request) => Limit::perMinute(60)->by('typing:'.($request->user()?->id ?? $request->ip())));

        RateLimiter::for('chat-sync', fn (Request $request) => Limit::perMinute(90)->by('sync:'.($request->user()?->id ?? $request->ip())));

        // Phone-book matching reveals who is registered: keep it slow.
        RateLimiter::for('contacts-sync', fn (Request $request) => [
            Limit::perMinute(6)->by('contacts:'.($request->user()?->id ?? $request->ip())),
            Limit::perDay(50)->by('contacts-day:'.($request->user()?->id ?? $request->ip())),
        ]);

        RateLimiter::for('calls-start', fn (Request $request) => [
            Limit::perMinute(10)->by('calls:'.($request->user()?->id ?? $request->ip())),
            Limit::perHour(120)->by('calls-hour:'.($request->user()?->id ?? $request->ip())),
        ]);

        // ICE candidates, session descriptions and polling while the WebSocket is down.
        RateLimiter::for('calls-signal', fn (Request $request) => Limit::perMinute(600)->by('call-signals:'.($request->user()?->id ?? $request->ip())));
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConversationResource;
use App\Models\ChatSetting;
use App\Models\Conversation;
use App\Services\ConversationService;
use App\Services\WallpaperService;
use App\Support\ChatPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Phase 8 — wallpaper for all chats or one chat (D2).
 */
class ChatPreferencesController extends Controller
{
    public function __construct(
        private readonly WallpaperService $wallpapers,
        private readonly ConversationService $conversations,
    ) {}

    /** Settings → Chats → Wallpaper (the default for every chat). */
    public function updateWallpaper(Request $request): JsonResponse
    {
        $validated = $request->validate($this->wallpaperRules() + [
            'dim' => ['sometimes', 'integer', 'min:0', 'max:'.ChatPreferences::MAX_WALLPAPER_DIM],
        ]);

        $user = $this->wallpapers->updateForUser(
            $request->user(),
            $validated['wallpaper'],
            $request->file('photo'),
            isset($validated['dim']) ? (int) $validated['dim'] : null,
        );

        return response()->json([
            'message' => 'Wallpaper saved.',
            'wallpaper' => WallpaperService::payload($user->wallpaper, $user->wallpaper_path, 'settings.wallpaper.show') + ['dim' => (int) $user->wallpaper_dim],
        ]);
    }

    public function showWallpaper(Request $request): BinaryFileResponse
    {
        $user = $request->user();
        abort_unless($user->wallpaper === ChatPreferences::WALLPAPER_CUSTOM, 404);

        return $this->photo($this->wallpapers->file($user->wallpaper_path));
    }

    /** Wallpaper of one chat ("default" = use the one from Settings). */
    public function updateChatWallpaper(Request $request, Conversation $conversation): ConversationResource
    {
        Gate::authorize('view', $conversation);
        $validated = $request->validate($this->wallpaperRules());

        $this->wallpapers->updateForChat($request->user(), $conversation, $validated['wallpaper'], $request->file('photo'));

        return new ConversationResource($this->conversations->loadForUser($conversation, $request->user()));
    }

    public function showChatWallpaper(Request $request, Conversation $conversation): BinaryFileResponse
    {
        Gate::authorize('view', $conversation);

        $setting = ChatSetting::query()->where('user_id', $request->user()->getKey())->where('conversation_id', $conversation->getKey())->first();
        abort_unless($setting?->wallpaper === ChatPreferences::WALLPAPER_CUSTOM, 404);

        return $this->photo($this->wallpapers->file($setting->wallpaper_path));
    }

    private function wallpaperRules(): array
    {
        return [
            'wallpaper' => ['required', Rule::in(ChatPreferences::wallpapers())],
            'photo' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.(int) config('chat.uploads.image.max_kb'), 'dimensions:max_width=8000,max_height=8000'],
        ];
    }

    private function photo(?string $path): BinaryFileResponse
    {
        abort_if($path === null, 404);

        return response()->file($path, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=604800',
            'X-Content-Type-Options' => 'nosniff',
        ])->setPrivate();
    }
}

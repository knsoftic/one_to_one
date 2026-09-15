<?php

namespace App\Services;

use App\Models\ChatSetting;
use App\Models\Conversation;
use App\Models\User;
use App\Support\ChatPreferences;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * D2 — chat wallpaper: a default for all chats (Settings → Chats) and one per chat.
 * Uploaded photos are re-encoded (no EXIF / GPS) and kept private on the chat disk.
 */
class WallpaperService
{
    public const DIRECTORY = 'wallpapers';

    private const MAX_EDGE = 1920;

    public function __construct(private readonly ImageService $images) {}

    public function updateForUser(User $user, string $key, ?UploadedFile $photo = null, ?int $dim = null): User
    {
        [$wallpaper, $path] = $this->resolve($user, $key, $photo, $user->wallpaper, $user->wallpaper_path);

        $user->forceFill(['wallpaper' => $wallpaper, 'wallpaper_path' => $path]);
        if ($dim !== null) {
            $user->wallpaper_dim = max(0, min(ChatPreferences::MAX_WALLPAPER_DIM, $dim));
        }
        $user->save();

        return $user;
    }

    public function updateForChat(User $user, Conversation $conversation, string $key, ?UploadedFile $photo = null): ChatSetting
    {
        $setting = ChatSetting::query()->firstOrNew(['user_id' => $user->getKey(), 'conversation_id' => $conversation->getKey()]);
        [$wallpaper, $path] = $this->resolve($user, $key, $photo, $setting->wallpaper, $setting->wallpaper_path);

        $setting->forceFill(['wallpaper' => $wallpaper, 'wallpaper_path' => $path])->save();

        return $setting;
    }

    /** Absolute path of a stored wallpaper photo, or null when it is gone. */
    public function file(?string $path): ?string
    {
        $disk = Storage::disk($this->disk());

        return $path && $disk->exists($path) ? $disk->path($path) : null;
    }

    /** Every wallpaper photo of an account (account deletion). */
    public function deleteAllFor(User $user): void
    {
        Storage::disk($this->disk())->deleteDirectory(self::DIRECTORY.'/'.$user->getKey());
    }

    /**
     * What the apps need: the choice and, for a photo, where to load it.
     *
     * @return array{key: string, url: ?string}
     */
    public static function payload(?string $key, ?string $path, string $route, array $parameters = []): array
    {
        $custom = $key === ChatPreferences::WALLPAPER_CUSTOM && $path;

        return [
            'key' => $custom || in_array($key, ChatPreferences::WALLPAPER_PRESETS, true) ? $key : ChatPreferences::WALLPAPER_DEFAULT,
            // The version changes with every new photo, so browsers never show an old one.
            'url' => $custom ? route($route, $parameters + ['v' => substr(md5($path), 0, 10)], false) : null,
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string} [stored key, stored photo path]
     */
    private function resolve(User $user, string $key, ?UploadedFile $photo, ?string $currentKey, ?string $currentPath): array
    {
        if ($key === ChatPreferences::WALLPAPER_CUSTOM) {
            if (! $photo) {
                // Saving again (e.g. only the dimming changed) keeps the photo.
                if ($currentKey === ChatPreferences::WALLPAPER_CUSTOM && $this->file($currentPath)) {
                    return [ChatPreferences::WALLPAPER_CUSTOM, $currentPath];
                }

                throw ValidationException::withMessages(['photo' => 'Choose a photo for the wallpaper.']);
            }

            $path = $this->store($user, $photo);
            $this->delete($currentPath);

            return [ChatPreferences::WALLPAPER_CUSTOM, $path];
        }

        $this->delete($currentPath);

        return [$key === ChatPreferences::WALLPAPER_DEFAULT ? null : $key, null];
    }

    private function store(User $user, UploadedFile $photo): string
    {
        try {
            $image = $this->images->reencode((string) $photo->getRealPath(), self::MAX_EDGE, 'jpeg', 82);
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['photo' => 'This photo could not be read. Try a JPG or PNG.']);
        }

        $path = self::DIRECTORY.'/'.$user->getKey().'/'.Str::uuid()->toString().'.jpg';
        Storage::disk($this->disk())->put($path, $image['binary']);

        return $path;
    }

    private function delete(?string $path): void
    {
        if ($path) {
            Storage::disk($this->disk())->delete($path);
        }
    }

    private function disk(): string
    {
        return (string) config('chat.uploads.disk');
    }
}

<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\EmailChangedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AccountService
{
    public function __construct(private readonly ImageService $images) {}

    /**
     * Create a new user account.
     *
     * @param  array{name:string,username:string,email:string,phone:string,password:string}  $data
     */
    public function register(array $data, ?UploadedFile $avatar = null): User
    {
        $avatarPath = $avatar ? $this->storeAvatar($avatar) : null;

        try {
            return DB::transaction(fn () => User::create([
                'name' => $data['name'],
                'username' => filled($data['username'] ?? null) ? $data['username'] : $this->usernameFor($data['name']),
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'],
                'password' => $data['password'],
                'profile_image' => $avatarPath,
            ]));
        } catch (\Throwable $e) {
            $this->images->deleteAvatar($avatarPath);

            throw $e;
        }
    }

    /**
     * A free username made from the name: "Awais Ahmed" → "awais.ahmed", "awais.ahmed2"…
     */
    public function usernameFor(string $name): string
    {
        $base = (string) str(Str::ascii($name))->lower()->replaceMatches('/[^a-z0-9]+/', '.')->trim('.')->limit(24, '');
        $base = trim($base, '._');
        if (mb_strlen($base) < 3 || in_array($base, config('chat.reserved_usernames'), true)) {
            $base = 'user'.($base !== '' ? '.'.$base : '');
        }

        $candidate = $base;
        for ($i = 2; User::query()->where('username', $candidate)->exists(); $i++) {
            $candidate = $i > 50 ? $base.'.'.Str::lower(Str::random(4)) : $base.$i;
        }

        return $candidate;
    }

    /**
     * Update profile details and (optionally) the profile picture.
     */
    public function updateProfile(User $user, array $data, ?UploadedFile $avatar = null, bool $removeAvatar = false): User
    {
        $user->fill([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'] ?? null,
        ]);

        if (array_key_exists('about', $data)) {
            $about = trim((string) preg_replace('/\s+/u', ' ', (string) $data['about']));
            $user->about = $about === '' ? null : mb_substr($about, 0, 139);
        }

        $oldEmail = $user->isDirty('email') ? $user->getOriginal('email') : null;
        if ($oldEmail !== null) {
            $user->email_verified_at = null;
        }

        if ($avatar) {
            $user->profile_image = $this->storeAvatar($avatar, $user->profile_image, 'profile');
        } elseif ($removeAvatar && $user->profile_image) {
            $this->images->deleteAvatar($user->profile_image);
            $user->profile_image = null;
        }

        $user->save();

        if ($oldEmail) {
            Notification::route('mail', $oldEmail)->notify(new EmailChangedNotification($user->name, (string) $user->email));
        }

        return $user;
    }

    /** A4 — the secret in the profile QR code, made the first time it is needed. */
    public function qrToken(User $user, bool $reset = false): string
    {
        if ($reset || ! $user->qr_token) {
            $user->forceFill(['qr_token' => Str::random(32)])->save();
        }

        return (string) $user->qr_token;
    }

    /**
     * A2 — a new mobile number for the same account (checked by the caller).
     */
    public function changePhone(User $user, string $phone, bool $verified): User
    {
        $user->forceFill(['phone' => $phone, 'phone_verified_at' => $verified ? now() : null])->save();

        return $user;
    }

    /**
     * Set or replace only the profile picture (used by the post-registration step).
     */
    public function updateAvatar(User $user, UploadedFile $avatar): User
    {
        $user->profile_image = $this->storeAvatar($avatar, $user->profile_image);
        $user->save();

        return $user;
    }

    /**
     * @param  string|null  $keepSessionId  this browser stays signed in
     */
    public function updatePassword(User $user, string $password, ?string $keepSessionId = null): void
    {
        $user->forceFill([
            'password' => $password,
            'remember_token' => Str::random(60),
        ])->save();

        // Phones and browsers signed in with the old password must sign in again.
        $user->deviceTokens()->delete();
        app(WebPushService::class)->forgetUser($user, $keepSessionId);
        DB::table('sessions')->where('user_id', $user->getKey())
            ->when($keepSessionId, fn ($q) => $q->where('id', '!=', $keepSessionId))
            ->delete();
    }

    public function updatePreferences(User $user, array $preferences): User
    {
        $user->fill(array_intersect_key($preferences, array_flip([
            'theme', 'notifications_enabled', 'notification_sound',
            'font_size', 'notification_tone', 'notification_vibrate', 'auto_download',
            'last_seen_privacy', 'online_privacy', 'photo_privacy', 'about_privacy', 'read_receipts',
        ])));
        $user->save();

        return $user;
    }

    private function storeAvatar(UploadedFile $file, ?string $previous = null, string $errorBag = 'default'): string
    {
        try {
            return $this->images->storeAvatar($file, $previous);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['profile_image' => $e->getMessage()])->errorBag($errorBag);
        }
    }
}

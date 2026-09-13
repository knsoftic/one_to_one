<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
                'username' => $data['username'],
                'email' => $data['email'],
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
     * Update profile details and (optionally) the profile picture.
     */
    public function updateProfile(User $user, array $data, ?UploadedFile $avatar = null, bool $removeAvatar = false): User
    {
        $user->fill([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'phone' => $data['phone'],
        ]);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        if ($avatar) {
            $user->profile_image = $this->storeAvatar($avatar, $user->profile_image, 'profile');
        } elseif ($removeAvatar && $user->profile_image) {
            $this->images->deleteAvatar($user->profile_image);
            $user->profile_image = null;
        }

        $user->save();

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

    public function updatePassword(User $user, string $password): void
    {
        $user->forceFill([
            'password' => $password,
            'remember_token' => Str::random(60),
        ])->save();
    }

    public function updatePreferences(User $user, array $preferences): User
    {
        $user->fill(array_intersect_key($preferences, array_flip(['theme', 'notifications_enabled', 'notification_sound'])));
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

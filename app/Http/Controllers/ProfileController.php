<?php

namespace App\Http\Controllers;

use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Requests\Profile\UpdatePreferencesRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Services\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
            'blockedUsers' => $request->user()->blockedUsers()->orderBy('name')->get(),
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $this->accounts->updateProfile(
            $request->user(),
            $request->safe()->only(['name', 'username', 'email', 'phone']),
            $request->file('profile_image'),
            $request->boolean('remove_profile_image'),
        );

        return redirect()->route('profile.edit')->with('status', 'Your profile has been updated.');
    }

    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        $this->accounts->updatePassword($request->user(), $request->validated('password'));

        $request->session()->regenerate();

        return redirect()->route('profile.edit', ['tab' => 'security'])->with('status', 'Your password has been changed.');
    }

    public function updatePreferences(UpdatePreferencesRequest $request): JsonResponse|RedirectResponse
    {
        $data = $request->validated();

        foreach (['notifications_enabled', 'notification_sound'] as $flag) {
            if ($request->has($flag)) {
                $data[$flag] = $request->boolean($flag);
            }
        }

        $user = $this->accounts->updatePreferences($request->user(), $data);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Preferences saved.',
                'user' => new UserResource($user),
                'preferences' => $user->only(['theme', 'notifications_enabled', 'notification_sound']),
            ]);
        }

        return redirect()->route('profile.edit', ['tab' => 'preferences'])->with('status', 'Your preferences have been saved.');
    }
}

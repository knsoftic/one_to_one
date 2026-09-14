<?php

namespace App\Http\Controllers;

use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Requests\Profile\UpdatePreferencesRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AccountService;
use App\Services\ContactService;
use App\Services\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    public function edit(Request $request): View
    {
        $user = $request->user();
        $blocked = $user->blockedUsers()->orderBy('name')->get();

        // People you chat with, to block from here too (P5).
        $partnerIds = Conversation::query()
            ->where('type', Conversation::TYPE_DIRECT)
            ->whereNotNull('last_message_id')
            ->where(fn ($q) => $q->where('user_one_id', $user->id)->orWhere('user_two_id', $user->id))
            ->latest('updated_at')
            ->limit(100)
            ->get(['user_one_id', 'user_two_id'])
            ->map(fn (Conversation $c) => $c->otherParticipantId($user))
            ->reject(fn (int $id) => $id === (int) $user->id || $blocked->contains('id', $id))
            ->unique();

        return view('profile.edit', [
            'user' => $user,
            'blockedUsers' => $blocked,
            'blockCandidates' => User::query()->whereKey($partnerIds)->active()->orderBy('name')->get(),
            'savedNames' => app(ContactService::class)->savedNames($user, $blocked->pluck('id')->merge($partnerIds)->all()),
            'sessions' => app(SessionService::class)->list($user, $request),
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $this->accounts->updateProfile(
            $request->user(),
            $request->safe()->only(['name', 'username', 'email', 'phone', 'about']),
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

        foreach (['notifications_enabled', 'notification_sound', 'read_receipts'] as $flag) {
            if ($request->has($flag)) {
                $data[$flag] = $request->boolean($flag);
            }
        }

        $user = $this->accounts->updatePreferences($request->user(), $data);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Preferences saved.',
                'user' => new UserResource($user),
                'preferences' => $user->only(['theme', 'notifications_enabled', 'notification_sound', 'last_seen_privacy', 'online_privacy', 'photo_privacy', 'about_privacy', 'read_receipts']),
            ]);
        }

        return redirect()->route('profile.edit', ['tab' => 'preferences'])->with('status', 'Your preferences have been saved.');
    }
}

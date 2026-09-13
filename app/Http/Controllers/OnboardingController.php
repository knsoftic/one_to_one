<?php

namespace App\Http\Controllers;

use App\Http\Requests\Profile\UpdateAvatarRequest;
use App\Services\AccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Optional step right after registration: add a profile photo (DP).
 */
class OnboardingController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    public function photo(Request $request): View
    {
        return view('onboarding.photo', ['user' => $request->user()]);
    }

    public function savePhoto(UpdateAvatarRequest $request): RedirectResponse
    {
        $this->accounts->updateAvatar($request->user(), $request->file('profile_image'));

        return redirect()->route('chat.index')->with('status', 'Your profile photo has been added.');
    }
}

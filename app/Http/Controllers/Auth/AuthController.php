<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\AccountService;
use App\Services\PresenceService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly PresenceService $presence,
    ) {}

    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(LoginRequest $request): RedirectResponse
    {
        $user = $request->authenticate();

        $request->session()->regenerate();
        $this->presence->touch($user, force: true);

        return redirect()->intended(route('chat.index'));
    }

    public function showRegister(): View
    {
        return view('auth.register');
    }

    public function register(RegisterRequest $request): RedirectResponse
    {
        $user = $this->accounts->register(
            $request->safe()->only(['name', 'username', 'email', 'phone', 'password']),
            $request->file('profile_image'),
        );

        event(new Registered($user));

        Auth::login($user);
        $request->session()->regenerate();
        $this->presence->touch($user, force: true);

        // Profile photo is optional and added in a separate, skippable step.
        if (! $user->profile_image) {
            return redirect()->route('onboarding.photo');
        }

        return redirect()->route('chat.index')
            ->with('status', "Welcome, {$user->name}! Your account has been created.");
    }

    public function logout(Request $request): RedirectResponse
    {
        if ($user = $request->user()) {
            $this->presence->markOffline($user);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'You have been signed out.');
    }
}

<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PresenceService;
use App\Services\TwoStepService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * P7 — the PIN step of signing in, "Forgot PIN?", and the settings to turn it on or off.
 */
class TwoStepController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly TwoStepService $twoStep,
        private readonly PresenceService $presence,
    ) {}

    public function show(Request $request): View|RedirectResponse
    {
        $pending = $this->twoStep->pending($request);

        return $pending
            ? view('auth.two-step', ['user' => $pending['user']])
            : redirect()->route('login')->with('status', 'Sign in again to continue.');
    }

    public function verify(Request $request): RedirectResponse
    {
        $pending = $this->twoStep->pending($request);
        if (! $pending) {
            return redirect()->route('login')->with('status', 'Sign in again to continue.');
        }

        $request->validate(['pin' => ['required', 'digits:6']], ['pin.digits' => 'Enter the 6-digit PIN.', 'pin.required' => 'Enter the 6-digit PIN.']);
        $user = $pending['user'];
        $key = 'two-step:'.$user->getKey().'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['pin' => 'Too many tries. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minute(s) or use "Forgot PIN?".']);
        }

        if (! $this->twoStep->checkPin($user, $request->string('pin')->toString())) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages(['pin' => 'That PIN is not correct.']);
        }

        RateLimiter::clear($key);
        $this->twoStep->finishChallenge($request);

        Auth::login($user, $pending['remember']);
        $request->session()->regenerate();
        $this->twoStep->trust($user, $request);
        $this->presence->touch($user, force: true);

        return redirect()->intended(route('chat.index'));
    }

    public function forgot(Request $request): RedirectResponse
    {
        $pending = $this->twoStep->pending($request);
        if (! $pending) {
            return redirect()->route('login');
        }

        $key = 'two-step-reset:'.$pending['user']->getKey();
        if (! RateLimiter::tooManyAttempts($key, 3)) {
            RateLimiter::hit($key, 3600);
            $this->twoStep->sendReset($pending['user']);
        }

        return back()->with('status', 'We emailed you a link to turn off two-step verification.');
    }

    /** The emailed link (signed): turn two-step verification off. */
    public function reset(User $user): RedirectResponse
    {
        $this->twoStep->disable($user);

        return redirect()->route('login')->with('status', 'Two-step verification is off. Sign in with your password.');
    }

    /* ------------------------------------------------------------------ */
    /* Settings */
    /* ------------------------------------------------------------------ */

    public function enable(Request $request): RedirectResponse
    {
        $request->validateWithBag('twoStep', [
            'pin' => ['required', 'digits:6', 'confirmed'],
            'current_password' => ['required', 'current_password'],
        ], ['pin.digits' => 'The PIN must be 6 digits.', 'pin.confirmed' => 'The two PINs don\'t match.']);

        $this->twoStep->enable($request->user(), $request->string('pin')->toString(), $request);

        return $this->toSettings('Two-step verification is on. You\'ll be asked for the PIN when you sign in on a new device.');
    }

    public function change(Request $request): RedirectResponse
    {
        $request->validateWithBag('twoStep', [
            'pin' => ['required', 'digits:6', 'confirmed'],
            'current_password' => ['required', 'current_password'],
        ], ['pin.digits' => 'The PIN must be 6 digits.', 'pin.confirmed' => 'The two PINs don\'t match.']);

        abort_unless($this->twoStep->enabled($request->user()), 404);
        $this->twoStep->changePin($request->user(), $request->string('pin')->toString());

        return $this->toSettings('Your two-step PIN has been changed.');
    }

    public function disable(Request $request): RedirectResponse
    {
        $request->validateWithBag('twoStep', ['current_password' => ['required', 'current_password']]);
        $this->twoStep->disable($request->user());

        return $this->toSettings('Two-step verification is off.');
    }

    public function forgetDevices(Request $request): RedirectResponse
    {
        $count = $this->twoStep->forgetDevices($request->user());

        return $this->toSettings($count === 1 ? '1 browser will be asked for the PIN again.' : "{$count} browsers will be asked for the PIN again.");
    }

    private function toSettings(string $status): RedirectResponse
    {
        return redirect()->route('profile.edit', ['tab' => 'security'])->with('status', $status);
    }
}

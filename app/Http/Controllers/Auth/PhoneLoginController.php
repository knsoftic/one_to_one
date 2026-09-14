<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\OtpException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OtpService;
use App\Services\PresenceService;
use App\Services\TwoStepService;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * A1 — log in with the mobile number and a 6-digit SMS code (no password).
 */
class PhoneLoginController extends Controller
{
    private const SESSION_KEY = 'phone_login';

    public function __construct(
        private readonly OtpService $otp,
        private readonly TwoStepService $twoStep,
        private readonly PresenceService $presence,
    ) {}

    public function show(): View
    {
        abort_unless($this->otp->available(), 404);

        return view('auth.phone-login');
    }

    public function send(Request $request): RedirectResponse
    {
        abort_unless($this->otp->available(), 404);

        $request->merge(['phone' => Phone::normalize((string) $request->input('phone'))]);
        $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{7,15}$/'],
            'remember' => ['nullable', 'boolean'],
        ], ['phone.required' => 'Enter your mobile number.', 'phone.regex' => 'Enter a valid mobile number (7–15 digits, optional leading +).']);

        $phone = $request->string('phone')->toString();
        $user = $this->otp->userForPhone($phone);

        try {
            // A number without an account gets no SMS, but the next page looks the same.
            $otp = $this->otp->send($user ? (string) $user->phone : $phone, OtpService::LOGIN, $user, $request, deliver: $user !== null);
        } catch (OtpException $e) {
            throw ValidationException::withMessages(['phone' => $e->getMessage()]);
        }

        $this->otp->remember($request, self::SESSION_KEY, [
            'phone' => $phone,
            'otp_id' => $otp?->getKey(),
            'remember' => $request->boolean('remember'),
        ]);

        return redirect()->route('login.phone.code');
    }

    public function code(Request $request): View|RedirectResponse
    {
        $pending = $this->otp->pending($request, self::SESSION_KEY);
        if (! $pending) {
            return redirect()->route('login.phone')->with('status', 'Enter your mobile number again.');
        }

        return view('auth.phone-verify', [
            'phone' => $pending['phone'],
            'resendIn' => $this->otp->resendIn($this->otpPhone($pending), OtpService::LOGIN),
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $pending = $this->otp->pending($request, self::SESSION_KEY);
        if (! $pending) {
            return redirect()->route('login.phone')->with('status', 'Enter your mobile number again.');
        }

        $request->validate(['code' => ['required', 'digits:6']], ['code.required' => 'Enter the 6-digit code.', 'code.digits' => 'Enter the 6-digit code.']);

        try {
            if (! $pending['otp_id']) {
                // No account: count the tries like a real code would.
                $pending['tries']++;
                $request->session()->put(self::SESSION_KEY, $pending);

                throw new OtpException($pending['tries'] >= OtpService::MAX_ATTEMPTS ? 'Too many wrong codes. Ask for a new one.' : 'That code is not correct.');
            }

            $otp = $this->otp->verify((int) $pending['otp_id'], OtpService::LOGIN, $request->string('code')->toString());
        } catch (OtpException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        $user = User::query()->whereKey($otp->user_id)->active()->first();
        $this->otp->forget($request, self::SESSION_KEY);

        if (! $user) {
            return redirect()->route('login')->withErrors(['login' => 'This account is not available. Please contact support.']);
        }

        if ((string) $user->phone === $otp->phone && $user->phone_verified_at === null) {
            $user->forceFill(['phone_verified_at' => now()])->save();
        }

        // Two-step verification still asks for the PIN on a new browser (P7).
        if ($this->twoStep->requiresChallenge($user, $request)) {
            $request->session()->regenerate();
            $this->twoStep->startChallenge($request, $user, (bool) $pending['remember']);

            return redirect()->route('two-step.challenge');
        }

        Auth::login($user, (bool) $pending['remember']);
        $request->session()->regenerate();
        $this->presence->touch($user, force: true);

        return redirect()->intended(route('chat.index'));
    }

    public function resend(Request $request): RedirectResponse
    {
        $pending = $this->otp->pending($request, self::SESSION_KEY);
        if (! $pending) {
            return redirect()->route('login.phone')->with('status', 'Enter your mobile number again.');
        }

        $user = $this->otp->userForPhone($pending['phone']);

        try {
            $otp = $this->otp->send($user ? (string) $user->phone : $pending['phone'], OtpService::LOGIN, $user, $request, deliver: $user !== null);
        } catch (OtpException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        $this->otp->remember($request, self::SESSION_KEY, [
            'phone' => $pending['phone'],
            'otp_id' => $otp?->getKey(),
            'remember' => $pending['remember'],
        ]);

        return redirect()->route('login.phone.code')->with('status', 'We sent you a new code.');
    }

    /** The number the code went to (the account's own number when it is written differently). */
    private function otpPhone(array $pending): string
    {
        return $this->otp->userForPhone($pending['phone'])?->phone ?? $pending['phone'];
    }
}

<?php

namespace App\Http\Controllers;

use App\Exceptions\OtpException;
use App\Models\User;
use App\Services\AccountService;
use App\Services\OtpService;
use App\Support\Phone;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A2 — Change number: the account (chats, groups, contacts) stays, only the number changes.
 * When SMS is set up, the new number is confirmed with a 6-digit code first.
 */
class PhoneChangeController extends Controller
{
    public const SESSION_KEY = 'phone_change';

    public function __construct(
        private readonly OtpService $otp,
        private readonly AccountService $accounts,
    ) {}

    public function start(Request $request): RedirectResponse
    {
        $user = $request->user();
        $request->merge(['phone' => Phone::forAccount((string) $request->input('phone'))]);

        $request->validateWithBag('phone', [
            'phone' => ['required', 'string', 'regex:/^\+?[0-9]{7,15}$/', $this->notTaken($user)],
            'current_password' => ['required', 'current_password'],
        ], [
            'phone.required' => 'Enter your new mobile number.',
            'phone.regex' => 'Enter a valid mobile number (7–15 digits, optional leading +).',
        ]);

        $phone = $request->string('phone')->toString();

        if (! $this->otp->available()) {
            $this->accounts->changePhone($user, $phone, verified: false);

            return $this->toSettings("Your number has been changed to {$phone}.");
        }

        try {
            $otp = $this->otp->send($phone, OtpService::CHANGE_NUMBER, $user, $request);
        } catch (OtpException $e) {
            throw ValidationException::withMessages(['phone' => $e->getMessage()])->errorBag('phone');
        }

        $this->otp->remember($request, self::SESSION_KEY, ['phone' => $phone, 'otp_id' => $otp->getKey()]);

        return $this->toSettings("We sent a 6-digit code to {$phone}.");
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = $request->user();
        $pending = $this->otp->pending($request, self::SESSION_KEY);
        if (! $pending) {
            return $this->toSettings('Enter your new number again.');
        }

        $request->validateWithBag('phone', ['code' => ['required', 'digits:6']], ['code.required' => 'Enter the 6-digit code.', 'code.digits' => 'Enter the 6-digit code.']);

        try {
            $this->otp->verify((int) $pending['otp_id'], OtpService::CHANGE_NUMBER, $request->string('code')->toString(), $user);
        } catch (OtpException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()])->errorBag('phone');
        }

        $this->otp->forget($request, self::SESSION_KEY);

        // Someone may have registered the number in the meantime.
        validator(['phone' => $pending['phone']], ['phone' => [$this->notTaken($user)]])->validateWithBag('phone');

        $this->accounts->changePhone($user, $pending['phone'], verified: true);

        return $this->toSettings("Your number has been changed to {$pending['phone']}. Your chats, groups and contacts stay the same.");
    }

    public function resend(Request $request): RedirectResponse
    {
        $pending = $this->otp->pending($request, self::SESSION_KEY);
        if (! $pending) {
            return $this->toSettings('Enter your new number again.');
        }

        try {
            $otp = $this->otp->send($pending['phone'], OtpService::CHANGE_NUMBER, $request->user(), $request);
        } catch (OtpException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()])->errorBag('phone');
        }

        $this->otp->remember($request, self::SESSION_KEY, ['phone' => $pending['phone'], 'otp_id' => $otp->getKey()]);

        return $this->toSettings("We sent a new code to {$pending['phone']}.");
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->otp->forget($request, self::SESSION_KEY);

        return $this->toSettings('Your number was not changed.');
    }

    /** The number is not used by any other account, however it is written. */
    private function notTaken(User $user): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($user) {
            $value = (string) $value;
            if ($value === (string) $user->phone) {
                $fail('This is already your number.');

                return;
            }

            $suffix = Phone::suffix($value);
            $taken = User::query()->whereKeyNot($user->getKey())
                ->where(fn ($q) => $q->where('phone', $value)->when($suffix, fn ($q) => $q->orWhere('phone_suffix', $suffix)))
                ->pluck('phone')
                ->contains(fn ($phone) => $phone === $value || Phone::matches((string) $phone, $value));

            if ($taken) {
                $fail('This number is already used by another account.');
            }
        };
    }

    private function toSettings(string $status): RedirectResponse
    {
        return redirect()->to(route('profile.edit', ['tab' => 'account']).'#change-number')->with('status', $status);
    }
}

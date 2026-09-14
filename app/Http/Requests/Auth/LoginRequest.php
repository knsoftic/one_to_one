<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\Phone;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    private const MAX_ATTEMPTS = 5;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:191'],
            'password' => ['required', 'string', 'max:255'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['login' => 'email, username or mobile number'];
    }

    /**
     * Check the email / username / mobile number and password (does not sign in).
     *
     * @throws ValidationException
     */
    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();

        $credentials = [
            $this->identityConstraint(),
            'password' => $this->string('password')->toString(),
        ];

        if (! Auth::validate($credentials)) {
            RateLimiter::hit($this->throttleKey(), 60);

            throw ValidationException::withMessages(['login' => trans('auth.failed')]);
        }

        /** @var User $user */
        $user = Auth::getLastAttempted();

        if (! $user->isActive()) {
            throw ValidationException::withMessages([
                'login' => $user->status === User::STATUS_SUSPENDED
                    ? 'Your account has been suspended. Please contact support.'
                    : 'Your account is inactive. Please contact support.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        return $user;
    }

    /**
     * Query constraint matching the account by email, or by username / phone.
     */
    private function identityConstraint(): \Closure
    {
        $value = trim($this->string('login')->toString());

        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return fn (Builder $query) => $query->where('email', mb_strtolower($value));
        }

        $username = mb_strtolower(ltrim($value, '@'));
        $phone = preg_match('/^\+?[\d\s()\-]{7,20}$/', $value) ? Phone::normalize($value) : null;

        return fn (Builder $query) => $query->where(function (Builder $q) use ($username, $phone) {
            $q->where('username', $username);

            if ($phone !== null) {
                $q->orWhere('phone', $phone);
            }
        });
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'login' => trans('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('login')->toString()).'|'.$this->ip());
    }
}

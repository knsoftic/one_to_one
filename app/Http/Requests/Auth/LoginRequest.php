<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\BanService;
use App\Support\Phone;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
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
            // Sign-in history (admin panel): a wrong password for an existing account.
            if ($attempted = Auth::getLastAttempted()) {
                event(new Failed('web', $attempted, ['login' => $this->string('login')->toString()]));
            }

            throw ValidationException::withMessages(['login' => trans('auth.failed')]);
        }

        /** @var User $user */
        $user = Auth::getLastAttempted();

        // Banned (admin panel): the ban screen explains why and until when.
        $bans = app(BanService::class);
        if ($user->isBanned() && ! $bans->liftIfEnded($user)) {
            RateLimiter::clear($this->throttleKey());
            $bans->remember($this, $user);

            throw new HttpResponseException(redirect()->route('account.banned'));
        }

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
        $phones = preg_match('/^\+?[\d\s()\-]{7,20}$/', $value) ? array_unique([Phone::normalize($value), Phone::forAccount($value)]) : [];

        return fn (Builder $query) => $query->where(function (Builder $q) use ($username, $phones) {
            $q->where('username', $username);

            if ($phones !== []) {
                $q->orWhereIn('phone', $phones);
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

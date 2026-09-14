<?php

namespace App\Services;

use App\Exceptions\OtpException;
use App\Exceptions\SmsException;
use App\Models\OtpCode;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Phase 7 — 6-digit SMS codes: log in with the phone number (A1) and change the number (A2).
 */
class OtpService
{
    public const LOGIN = 'login';

    public const CHANGE_NUMBER = 'change_number';

    public const EXPIRES_MINUTES = 5;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    /** Codes one number may get in an hour. */
    public const MAX_PER_NUMBER = 5;

    /** Codes one network address may ask for in an hour. */
    public const MAX_PER_IP = 20;

    /** How long a code being entered stays in the session (resending renews it). */
    private const PENDING_MINUTES = 15;

    public function __construct(private readonly SmsService $sms) {}

    public function available(): bool
    {
        return $this->sms->available();
    }

    /**
     * Send a new code to $phone (the previous one stops working).
     *
     * With $deliver false nothing is sent, but the same limits apply: logging in with
     * a number that has no account looks exactly the same from the outside.
     *
     * @throws OtpException
     */
    public function send(string $phone, string $purpose, ?User $user, Request $request, bool $deliver = true): ?OtpCode
    {
        $digits = Phone::significantDigits($phone);
        $keys = [
            'wait' => "otp-wait:{$purpose}:{$digits}",
            'number' => "otp-number:{$digits}",
            'ip' => 'otp-ip:'.$request->ip(),
        ];

        if (RateLimiter::tooManyAttempts($keys['wait'], 1)) {
            throw new OtpException('Wait '.RateLimiter::availableIn($keys['wait']).' seconds before asking for another code.');
        }
        foreach (['number' => self::MAX_PER_NUMBER, 'ip' => self::MAX_PER_IP] as $limit => $max) {
            if (RateLimiter::tooManyAttempts($keys[$limit], $max)) {
                throw new OtpException('Too many codes were asked for. Try again in '.max(1, (int) ceil(RateLimiter::availableIn($keys[$limit]) / 60)).' minutes.');
            }
        }

        RateLimiter::hit($keys['wait'], self::RESEND_SECONDS);
        RateLimiter::hit($keys['number'], 3600);
        RateLimiter::hit($keys['ip'], 3600);

        if (! $deliver) {
            return null;
        }

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        OtpCode::query()->where('phone', $phone)->where('purpose', $purpose)->whereNull('consumed_at')->delete();
        $otp = OtpCode::query()->create([
            'phone' => $phone,
            'purpose' => $purpose,
            'user_id' => $user?->getKey(),
            'code_hash' => $this->hash($phone, $purpose, $code),
            'ip_address' => $request->ip(),
            'expires_at' => now()->addMinutes(self::EXPIRES_MINUTES),
        ]);

        try {
            $this->sms->send($phone, $this->message($code, $purpose));
        } catch (SmsException) {
            $otp->delete();
            RateLimiter::clear($keys['wait']);

            throw new OtpException("We couldn't send the SMS right now. Try again in a few minutes.");
        }

        return $otp;
    }

    /**
     * Check a typed code. The right code works once.
     *
     * @throws OtpException
     */
    public function verify(?int $otpId, string $purpose, string $code, ?User $user = null): OtpCode
    {
        $otp = $otpId
            ? OtpCode::query()->whereKey($otpId)->where('purpose', $purpose)->when($user, fn ($q) => $q->where('user_id', $user->getKey()))->first()
            : null;

        if (! $otp || ! $otp->isUsable()) {
            throw new OtpException('This code has expired. Ask for a new one.');
        }
        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            throw new OtpException('Too many wrong codes. Ask for a new one.');
        }

        if (! hash_equals($otp->code_hash, $this->hash($otp->phone, $purpose, $code))) {
            $otp->increment('attempts');

            throw new OtpException($otp->attempts >= self::MAX_ATTEMPTS ? 'Too many wrong codes. Ask for a new one.' : 'That code is not correct.');
        }

        // Only one request can use the code.
        if (OtpCode::query()->whereKey($otp->getKey())->whereNull('consumed_at')->update(['consumed_at' => now()]) === 0) {
            throw new OtpException('This code has expired. Ask for a new one.');
        }

        return $otp->refresh();
    }

    /** Seconds until another code can be sent to this number (0 = now). */
    public function resendIn(string $phone, string $purpose): int
    {
        $key = "otp-wait:{$purpose}:".Phone::significantDigits($phone);

        return RateLimiter::tooManyAttempts($key, 1) ? RateLimiter::availableIn($key) : 0;
    }

    /** The active account with this mobile number, written in any format. */
    public function userForPhone(string $phone): ?User
    {
        $exact = User::query()->active()->where('phone', $phone)->first();
        if ($exact || ($suffix = Phone::suffix($phone)) === null) {
            return $exact;
        }

        $matches = User::query()->active()->where('phone_suffix', $suffix)->get()
            ->filter(fn (User $user) => Phone::matches((string) $user->phone, $phone));

        // Never guess between two accounts.
        return $matches->count() === 1 ? $matches->first() : null;
    }

    /* ------------------------------------------------------------------ */
    /* The code being entered, kept in the session */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $data
     */
    public function remember(Request $request, string $key, array $data): void
    {
        $request->session()->put($key, $data + ['tries' => 0, 'expires_at' => now()->addMinutes(self::PENDING_MINUTES)->getTimestamp()]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pending(Request $request, string $key): ?array
    {
        $pending = $request->session()->get($key);

        if (! is_array($pending) || ($pending['expires_at'] ?? 0) < now()->getTimestamp()) {
            $request->session()->forget($key);

            return null;
        }

        return $pending;
    }

    public function forget(Request $request, string $key): void
    {
        $request->session()->forget($key);
    }

    /** Removes codes that expired more than a day ago. */
    public function prune(): int
    {
        return OtpCode::query()->where('expires_at', '<', now()->subDay())->delete();
    }

    private function hash(string $phone, string $purpose, string $code): string
    {
        return hash_hmac('sha256', "{$purpose}|{$phone}|{$code}", (string) config('app.key'));
    }

    private function message(string $code, string $purpose): string
    {
        $app = config('app.name');
        $text = $purpose === self::CHANGE_NUMBER
            ? "{$code} is your {$app} code to change your number to this phone."
            : "{$code} is your {$app} login code.";
        $text .= ' It expires in '.self::EXPIRES_MINUTES." minutes. Don't share it with anyone.";

        // Lets phones offer to fill the code in automatically (WebOTP).
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return $host ? "{$text}\n\n@{$host} #{$code}" : $text;
    }
}

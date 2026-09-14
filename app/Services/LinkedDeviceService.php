<?php

namespace App\Services;

use App\Models\LoginLink;
use App\Models\User;
use App\Support\UserAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Phase 6 — P10: linked devices. A login page shows a QR code (and a short code);
 * a phone that is signed in scans it and approves; the login page, which alone
 * knows the link's secret, is then signed in.
 */
class LinkedDeviceService
{
    public const LIFETIME_MINUTES = 3;

    /** No 0/O or 1/I, so codes are easy to type. */
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly TwoStepService $twoStep,
        private readonly PresenceService $presence,
    ) {}

    /**
     * @return array{token: string, secret: string, code: string, url: string, expires_at: string}
     */
    public function create(Request $request): array
    {
        LoginLink::query()->where('expires_at', '<', now()->subHour())->limit(200)->delete();

        $token = Str::random(40);
        $secret = Str::random(40);
        $code = collect(range(1, 8))->map(fn () => self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)])->implode('');

        $link = LoginLink::create([
            'token_hash' => hash('sha256', $token),
            'code_hash' => hash('sha256', $code),
            'secret_hash' => hash('sha256', $secret),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            'expires_at' => now()->addMinutes(self::LIFETIME_MINUTES),
        ]);

        return [
            'token' => $token,
            'secret' => $secret,
            'code' => substr($code, 0, 4).'-'.substr($code, 4),
            'url' => route('devices.link', $token),
            'expires_at' => $link->expires_at->toIso8601String(),
        ];
    }

    /**
     * The login page asks whether a phone approved it; if so it is signed in.
     *
     * @return array{state: string, redirect?: string}
     */
    public function poll(Request $request, string $token, string $secret): array
    {
        $link = LoginLink::query()->where('token_hash', hash('sha256', $token))->first();

        if (! $link || ! hash_equals($link->secret_hash, hash('sha256', $secret)) || $link->consumed_at || $link->expires_at->isPast()) {
            return ['state' => 'expired'];
        }

        if (! $link->approved_by) {
            return ['state' => 'waiting'];
        }

        $user = User::query()->whereKey($link->approved_by)->active()->first();
        $link->forceFill(['consumed_at' => now()])->save();
        if (! $user) {
            return ['state' => 'expired'];
        }

        Auth::guard('web')->login($user, true);
        $request->session()->regenerate();
        // Approved from a signed-in phone: this browser won't be asked for the two-step PIN.
        if ($this->twoStep->enabled($user)) {
            $this->twoStep->trust($user, $request);
        }
        $this->presence->touch($user, force: true);

        return ['state' => 'approved', 'redirect' => route('chat.index')];
    }

    /**
     * What the phone shows before approving.
     *
     * @return array{device: string, ip: ?string, created_at: string, expires_at: string}
     */
    public function describe(LoginLink $link): array
    {
        return [
            'device' => UserAgent::describe($link->user_agent),
            'ip' => $link->ip_address,
            'created_at' => $link->created_at->toIso8601String(),
            'expires_at' => $link->expires_at->toIso8601String(),
        ];
    }

    public function find(?string $token, ?string $code): LoginLink
    {
        $query = LoginLink::query()->pending();

        $link = match (true) {
            filled($token) => $query->where('token_hash', hash('sha256', (string) $token))->first(),
            filled($code) => $query->where('code_hash', hash('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code))))->first(),
            default => null,
        };

        if (! $link) {
            throw new HttpException(404, 'This code has expired. Refresh the login page on the other device and scan the new code.');
        }

        return $link;
    }

    public function approve(LoginLink $link, User $user): void
    {
        $link->forceFill(['approved_by' => $user->getKey(), 'approved_at' => now()])->save();
    }
}

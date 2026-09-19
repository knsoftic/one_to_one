<?php

namespace App\Http\Controllers;

use App\Services\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Refer & earn (Y2): the invite link a friend opens, and the person's own referral screen.
 */
class ReferralController extends Controller
{
    /** How long a remembered code stays valid in the session / the cookie. */
    public const SESSION_DAYS = 7;

    public const COOKIE_DAYS = 30;

    public function __construct(private readonly ReferralService $referrals) {}

    /** JSON for resources/js/ui/refer.js. */
    public function show(Request $request): JsonResponse
    {
        abort_unless($this->referrals->enabled(), 404);

        return response()->json($this->referrals->summary($request->user()));
    }

    /**
     * /r/{code}: remember who invited this visitor, then send a guest to sign up and a signed-in
     * person to their chats. An unknown code (or referrals being off) is just a plain link.
     */
    public function join(Request $request, string $code): RedirectResponse
    {
        $code = strtoupper($code);
        $referrer = $this->referrals->enabled() ? $this->referrals->resolve($code) : null;

        if (! $referrer) {
            return redirect()->route($request->user() ? 'chat.index' : 'register');
        }

        $request->session()->put('referral_code', $code);
        $request->session()->put('referral_code_until', now()->addDays(self::SESSION_DAYS)->timestamp);

        $target = $request->user() ? redirect()->route('chat.index') : redirect()->route('register', ['ref' => $code]);

        return $target->withCookie(cookie('ref', $code, self::COOKIE_DAYS * 24 * 60, null, null, null, false));
    }

    /** The code remembered for this visitor, if it has not gone stale. */
    public static function rememberedCode(Request $request): ?string
    {
        $until = (int) $request->session()->get('referral_code_until', 0);
        $code = $request->session()->get('referral_code');
        if (is_string($code) && $until >= now()->timestamp) {
            return $code;
        }

        $cookie = $request->cookie('ref');

        return is_string($cookie) && preg_match('/^[A-Z2-9]{8}$/', $cookie) ? $cookie : null;
    }
}

<?php

namespace App\Services;

use App\Http\Controllers\DeviceController;
use App\Models\User;
use App\Support\UserAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Phase 6 — P9: where you are signed in, and signing out of other devices.
 *
 * Sessions live in the "sessions" table. Session ids are never shown: each
 * session is referred to by a hash of its id. Signing a device out also
 * stops its phone notifications and makes "remember me" cookies on other
 * devices stop working (this device gets a new one).
 */
class SessionService
{
    /**
     * @return Collection<int, array{key: string, device: string, mobile: bool, app: bool, ip: ?string, last_active: string, current: bool}>
     */
    public function list(User $user, Request $request): Collection
    {
        $current = $request->session()->getId();

        return DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime'))->getTimestamp())
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn ($row) => [
                'key' => $this->keyFor($row->id),
                'device' => UserAgent::describe($row->user_agent),
                'mobile' => UserAgent::isMobile($row->user_agent),
                'app' => str_contains((string) $row->user_agent, '; wv)'),
                'ip' => $row->ip_address,
                'last_active' => Carbon::createFromTimestamp($row->last_activity)->toIso8601String(),
                'current' => hash_equals($row->id, $current),
            ])
            ->sortByDesc('current')
            ->values();
    }

    public function logout(User $user, string $key, Request $request): void
    {
        $row = DB::table('sessions')->where('user_id', $user->getKey())->get(['id', 'payload'])
            ->first(fn ($row) => hash_equals($this->keyFor($row->id), $key));

        if (! $row) {
            throw new HttpException(404, 'That device is already signed out.');
        }

        if (hash_equals($row->id, $request->session()->getId())) {
            throw new HttpException(422, 'Use "Log out" to sign out of this device.');
        }

        $this->revokeDevice($user, $row->payload);
        DB::table('sessions')->where('id', $row->id)->delete();
        $this->rotateRememberToken($user, $request);
    }

    /**
     * @return int devices signed out
     */
    public function logoutOthers(User $user, Request $request): int
    {
        $current = $request->session()->getId();
        $others = DB::table('sessions')->where('user_id', $user->getKey())->where('id', '!=', $current)->get(['id', 'payload']);

        foreach ($others as $row) {
            $this->revokeDevice($user, $row->payload);
        }

        // Phones whose app sessions already ended keep no notifications either.
        $keep = $request->session()->get(DeviceController::SESSION_KEY);
        $user->deviceTokens()->when(is_string($keep), fn ($q) => $q->where('token_hash', '!=', $keep))->delete();

        DB::table('sessions')->whereIn('id', $others->pluck('id'))->delete();
        $this->rotateRememberToken($user, $request);

        return $others->count();
    }

    private function keyFor(string $sessionId): string
    {
        return substr(hash('sha256', 'session|'.$sessionId), 0, 40);
    }

    /** Stop notifications on the phone that used this session. */
    private function revokeDevice(User $user, ?string $payload): void
    {
        $data = @unserialize((string) base64_decode((string) $payload, true), ['allowed_classes' => false]);
        $hash = is_array($data) ? ($data[DeviceController::SESSION_KEY] ?? null) : null;

        if (is_string($hash) && $hash !== '') {
            $user->deviceTokens()->where('token_hash', $hash)->delete();
        }
    }

    /** "Remember me" cookies elsewhere stop working; this device keeps a fresh one. */
    private function rotateRememberToken(User $user, Request $request): void
    {
        $remembered = $request->hasCookie(Auth::guard('web')->getRecallerName());

        $user->setRememberToken(Str::random(60));
        $user->save();

        if ($remembered) {
            Auth::guard('web')->login($user, true);
        }
    }
}

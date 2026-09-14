<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AccountService;
use App\Services\ContactService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A4 — Profile QR code: scanning someone's code opens a chat with them.
 * The code carries a random token that its owner can reset at any time.
 */
class ProfileQrController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    /** My code (made the first time it is shown). */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->accounts->qrToken($user);

        return response()->json($this->payload($user));
    }

    public function reset(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        $this->accounts->qrToken($user, reset: true);

        if ($request->expectsJson()) {
            return response()->json($this->payload($user) + ['message' => 'Your QR code has been reset. Old codes no longer work.']);
        }

        return redirect()->to(route('profile.edit', ['tab' => 'account']).'#qr-code')->with('status', 'Your QR code has been reset. Old codes no longer work.');
    }

    /** A code scanned with the phone's camera: open the app, which asks to start the chat. */
    public function page(Request $request, string $token): View
    {
        return view('chat.index', [
            'user' => $request->user(),
            'initialConversationId' => null,
            'profileQr' => ['token' => $token],
        ]);
    }

    /** Whose code is this? */
    public function lookup(Request $request): JsonResponse
    {
        $validated = $request->validate(['token' => ['required', 'string', 'regex:/^[A-Za-z0-9]{32}$/']], ['token.regex' => "This isn't a {$this->appName()} QR code."]);
        $viewer = $request->user();

        $owner = User::query()->active()->where('qr_token', $validated['token'])->first();

        // A code of someone who blocked you looks like an old code.
        if (! $owner || $owner->hasBlocked($viewer)) {
            return response()->json(['message' => "This QR code isn't valid anymore. Ask for a new one."], 404);
        }

        return response()->json([
            'user' => (new UserResource($owner))->resolve($request),
            'saved_name' => app(ContactService::class)->savedNames($viewer, [$owner->getKey()])[$owner->getKey()] ?? null,
            'self' => $owner->is($viewer),
        ]);
    }

    /**
     * @return array{url: string, token: string}
     */
    private function payload(User $user): array
    {
        return ['url' => route('profile-qr.page', $user->qr_token), 'token' => $user->qr_token];
    }

    private function appName(): string
    {
        return (string) config('app.name');
    }
}

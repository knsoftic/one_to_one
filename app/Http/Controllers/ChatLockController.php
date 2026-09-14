<?php

namespace App\Http\Controllers;

use App\Services\ChatLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * C9 — Secret code for "Locked chats": create / change / remove it, open and close the folder.
 */
class ChatLockController extends Controller
{
    public function __construct(private readonly ChatLockService $lock) {}

    public function storePin(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'pin' => ['required', 'string', 'regex:'.ChatLockService::PIN_PATTERN, 'confirmed'],
            // Changing an existing code needs the account password.
            'password' => [$this->lock->enabled($user) ? 'required' : 'nullable', 'string', 'current_password'],
        ], [
            'pin.regex' => 'The secret code must be 4 to 8 digits.',
            'pin.confirmed' => 'The two codes do not match.',
            'password.current_password' => 'The password is incorrect.',
        ]);

        $this->lock->setPin($user, $request->string('pin')->toString());
        $until = $this->lock->unlock();

        return $this->state($request, $until->toIso8601String());
    }

    public function destroyPin(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string', 'current_password']], [
            'password.current_password' => 'The password is incorrect.',
        ]);

        $this->lock->removePin($request->user());

        return $this->state($request, null);
    }

    public function unlock(Request $request): JsonResponse
    {
        $request->validate(['pin' => ['required', 'string', 'max:8']]);

        if (! $this->lock->check($request->user(), $request->string('pin')->toString())) {
            throw ValidationException::withMessages(['pin' => 'Wrong secret code.']);
        }

        return $this->state($request, $this->lock->unlock()->toIso8601String());
    }

    public function lock(Request $request): JsonResponse
    {
        $this->lock->lock();

        return $this->state($request, null);
    }

    private function state(Request $request, ?string $unlockedUntil): JsonResponse
    {
        return response()->json([
            'enabled' => $this->lock->enabled($request->user()),
            'unlocked_until' => $unlockedUntil,
        ]);
    }
}

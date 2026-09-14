<?php

namespace App\Services;

use App\Events\ChatSettingsUpdated;
use App\Models\ChatSetting;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Hash;

/**
 * C9 — Chat lock. Locked chats live in a "Locked chats" folder that opens with
 * the person's secret code. Opening it unlocks every locked chat in this
 * browser session for a few minutes (extended while they are in use).
 */
class ChatLockService
{
    public const SESSION_KEY = 'chat_lock.unlocked_until';

    public const PIN_PATTERN = '/^\d{4,8}$/';

    /** @var array<string, bool> */
    private array $locked = [];

    public function enabled(User $user): bool
    {
        return $user->chat_lock_pin !== null;
    }

    public function setPin(User $user, string $pin): void
    {
        $user->forceFill(['chat_lock_pin' => Hash::make($pin)])->save();
    }

    public function check(User $user, string $pin): bool
    {
        return $user->chat_lock_pin !== null && Hash::check($pin, $user->chat_lock_pin);
    }

    /**
     * Forgotten code: remove it (after the account password was confirmed);
     * every locked chat goes back to the chat list.
     */
    public function removePin(User $user): void
    {
        $ids = $this->lockedIds($user);

        $user->forceFill(['chat_lock_pin' => null])->save();
        ChatSetting::query()->where('user_id', $user->getKey())->whereNotNull('locked_at')->update(['locked_at' => null]);
        $this->lock();

        foreach ($ids as $id) {
            broadcast(new ChatSettingsUpdated($user->getKey(), $id))->toOthers();
        }
    }

    public function unlock(): CarbonInterface
    {
        $until = now()->addMinutes((int) config('chat.lock.unlock_minutes', 10));
        request()->session()->put(self::SESSION_KEY, $until->getTimestamp());

        return $until;
    }

    public function lock(): void
    {
        if (request()->hasSession()) {
            request()->session()->forget(self::SESSION_KEY);
        }
    }

    /** Is "Locked chats" open in this browser session? (Never for token / background requests.) */
    public function isUnlocked(): bool
    {
        $request = request();

        return $request->hasSession() && (int) $request->session()->get(self::SESSION_KEY, 0) > now()->getTimestamp();
    }

    public function unlockedUntil(): ?CarbonInterface
    {
        return $this->isUnlocked() ? now()->setTimestamp((int) request()->session()->get(self::SESSION_KEY)) : null;
    }

    /** Keep the folder open while locked chats are being used. */
    public function touch(): void
    {
        if ($this->isUnlocked()) {
            $this->unlock();
        }
    }

    public function isLocked(User $user, int $conversationId): bool
    {
        return $this->locked[$user->getKey().':'.$conversationId] ??= ChatSetting::query()
            ->where('user_id', $user->getKey())
            ->where('conversation_id', $conversationId)
            ->whereNotNull('locked_at')
            ->exists();
    }

    /** Can the user see this chat's messages right now? */
    public function canOpen(User $user, int $conversationId): bool
    {
        if (! $this->isLocked($user, $conversationId)) {
            return true;
        }

        if (! $this->isUnlocked()) {
            return false;
        }

        $this->touch();

        return true;
    }

    /**
     * @return list<int>
     */
    public function lockedIds(User $user): array
    {
        return ChatSetting::query()
            ->where('user_id', $user->getKey())
            ->whereNotNull('locked_at')
            ->pluck('conversation_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Locked chats whose messages must stay out of lists right now.
     *
     * @return list<int>
     */
    public function hiddenIds(User $user): array
    {
        return $this->isUnlocked() ? [] : $this->lockedIds($user);
    }

    /** Forget cached lock checks (after a chat was locked or unlocked). */
    public function forget(): void
    {
        $this->locked = [];
    }
}

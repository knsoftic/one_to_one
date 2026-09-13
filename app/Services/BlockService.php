<?php

namespace App\Services;

use App\Events\BlockStatusChanged;
use App\Models\BlockedUser;
use App\Models\Conversation;
use App\Models\User;
use InvalidArgumentException;

class BlockService
{
    public function __construct(private readonly TypingService $typing) {}

    public function block(User $user, User $target): BlockedUser
    {
        if ($user->is($target)) {
            throw new InvalidArgumentException('You cannot block yourself.');
        }

        $record = BlockedUser::query()->firstOrCreate([
            'user_id' => $user->getKey(),
            'blocked_user_id' => $target->getKey(),
        ]);

        $conversation = Conversation::query()->between($user, $target)->first();

        if ($conversation) {
            // Stop any typing indicator in either direction.
            $this->typing->set($conversation, $user, false);
        }

        broadcast(new BlockStatusChanged($user->getKey(), $target->getKey(), true, $conversation?->getKey()));

        return $record;
    }

    public function unblock(User $user, User $target): bool
    {
        $deleted = BlockedUser::query()
            ->where('user_id', $user->getKey())
            ->where('blocked_user_id', $target->getKey())
            ->delete() > 0;

        if ($deleted) {
            $conversation = Conversation::query()->between($user, $target)->first();
            broadcast(new BlockStatusChanged($user->getKey(), $target->getKey(), false, $conversation?->getKey()));
        }

        return $deleted;
    }
}

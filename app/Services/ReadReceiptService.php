<?php

namespace App\Services;

use App\Models\Message;
use App\Models\User;

/**
 * Phase 6 — P3: read receipts off.
 *
 * Messages are still marked read on the server (unread counts keep working),
 * but in a one-to-one chat where either person turned read receipts off,
 * nobody sees blue ticks: "read" shows as "delivered". Group receipts are
 * not affected, like WhatsApp. Remembered for one request.
 */
class ReadReceiptService
{
    /** @var array<int, bool> */
    private array $enabled = [];

    public function hiddenFor(Message $message): bool
    {
        if ($message->receiver_id === null || (int) $message->sender_id === (int) $message->receiver_id) {
            return false;
        }

        return ! $this->enabledFor((int) $message->sender_id) || ! $this->enabledFor((int) $message->receiver_id);
    }

    public function hiddenBetween(int $a, int $b): bool
    {
        return $a !== $b && (! $this->enabledFor($a) || ! $this->enabledFor($b));
    }

    /** What the sender may see. */
    public function statusOf(Message $message): string
    {
        $status = $message->status();

        return $status === Message::STATUS_SEEN && $this->hiddenFor($message) ? Message::STATUS_DELIVERED : $status;
    }

    public function forget(User $user): void
    {
        unset($this->enabled[(int) $user->getKey()]);
    }

    private function enabledFor(int $userId): bool
    {
        return $this->enabled[$userId] ??= (bool) (User::query()->whereKey($userId)->value('read_receipts') ?? true);
    }
}

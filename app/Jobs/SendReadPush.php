<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\PushService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The user read a conversation (on any device): remove its notification from
 * their phones, like WhatsApp does after reading on WhatsApp Web.
 */
class SendReadPush
{
    use Dispatchable;

    public function __construct(public int $userId, public int $conversationId) {}

    public function handle(PushService $push): void
    {
        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        try {
            $push->sendToUser($user, [
                'type' => 'read',
                'conversation_id' => $this->conversationId,
            ], PushService::PRIORITY_NORMAL, 86400);
        } catch (Throwable $e) {
            Log::warning('Read push failed: '.$e->getMessage(), ['conversation_id' => $this->conversationId]);
        }
    }
}

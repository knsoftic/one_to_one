<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\BroadcastService;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * G9 — Copy a broadcast list message into each recipient's chat, after the
 * response is sent (the sender never waits for a long list).
 */
class FanOutBroadcast
{
    use Dispatchable;

    public function __construct(public int $messageId) {}

    public function handle(BroadcastService $broadcasts): void
    {
        $message = Message::with(['conversation', 'sender'])->find($this->messageId);

        if ($message) {
            $broadcasts->fanOut($message);
        }
    }
}

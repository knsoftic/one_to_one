<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\BusinessService;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * X8 — a business's away message or greeting, sent after the response to the customer's message.
 */
class SendBusinessAutoReply
{
    use Dispatchable;

    public function __construct(public int $messageId) {}

    public function handle(BusinessService $business): void
    {
        $message = Message::query()->with('conversation')->find($this->messageId);
        if ($message) {
            $business->autoReplyTo($message);
        }
    }
}

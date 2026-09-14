<?php

namespace App\Jobs;

use App\Models\Call;
use App\Services\ContactService;
use App\Services\PrivacyService;
use App\Services\PushService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Calls on the callee's phones through Firebase:
 *  - incoming: full-screen ringing screen, even when the app is closed;
 *  - state:    the call was answered elsewhere or ended — stop ringing.
 */
class SendCallPush
{
    use Dispatchable;

    public const KIND_INCOMING = 'incoming';

    public const KIND_STATE = 'state';

    public function __construct(public int $callId, public string $kind) {}

    public function handle(PushService $push, ContactService $contacts): void
    {
        $call = Call::with(['caller', 'callee'])->find($this->callId);
        $callee = $call?->callee;
        $caller = $call?->caller;

        if (! $call || ! $callee || ! $caller || ! $callee->isActive()) {
            return;
        }

        try {
            if ($this->kind === self::KIND_INCOMING) {
                if (! $call->isRinging()) {
                    return;
                }

                $timeout = (int) config('chat.calls.ring_timeout_seconds', 45);

                $push->sendToUser($callee, [
                    'type' => 'call',
                    'call_id' => $call->id,
                    'conversation_id' => $call->conversation_id,
                    'call_type' => $call->type,
                    'caller_id' => $caller->id,
                    // The name saved in the callee's phone book, like WhatsApp.
                    'caller_name' => $contacts->savedNames($callee, [$caller->id])[$caller->id] ?? $caller->name,
                    'avatar_url' => app(PrivacyService::class)->canSeePhoto($caller, $callee) ? $caller->avatar_url : null,
                    'initials' => $caller->initials,
                    'avatar_hue' => $caller->avatar_hue,
                    'started_at' => $call->created_at?->getTimestampMs(),
                    'timeout_seconds' => $timeout,
                ], PushService::PRIORITY_HIGH, $timeout);

                return;
            }

            $push->sendToUser($callee, [
                'type' => 'call_state',
                'call_id' => $call->id,
                'conversation_id' => $call->conversation_id,
                'status' => $call->status,
                'end_reason' => $call->end_reason,
            ], PushService::PRIORITY_HIGH, 120);
        } catch (Throwable $e) {
            Log::warning('Call push failed: '.$e->getMessage(), ['call_id' => $call->id, 'kind' => $this->kind]);
        }
    }
}

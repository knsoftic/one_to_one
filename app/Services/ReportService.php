<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\UserReport;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Phase 6 — P6: report someone. Like WhatsApp, the last messages that person sent
 * in the chat go with the report, so admins can review it without reading chats.
 */
class ReportService
{
    public const EVIDENCE_MESSAGES = 5;

    public function __construct(private readonly BlockService $blocks) {}

    public function report(User $reporter, User $reported, string $reason, ?string $details, ?Conversation $conversation, bool $block): UserReport
    {
        if ($reporter->is($reported)) {
            throw new HttpException(422, "You can't report yourself.");
        }

        // Only a one-to-one chat between the two can be attached.
        if ($conversation && ($conversation->hasMembers() || ! $conversation->hasParticipant($reporter) || ! $conversation->hasParticipant($reported))) {
            $conversation = null;
        }

        // One open report per person is enough; a repeat updates it.
        $report = UserReport::query()->open()
            ->where('reporter_id', $reporter->getKey())
            ->where('reported_user_id', $reported->getKey())
            ->where('created_at', '>=', now()->subDay())
            ->first() ?? new UserReport(['reporter_id' => $reporter->getKey(), 'reported_user_id' => $reported->getKey()]);

        $report->fill([
            'conversation_id' => $conversation?->getKey(),
            'reason' => $reason,
            'details' => ($details = trim((string) $details)) === '' ? null : mb_substr($details, 0, 1000),
            'evidence' => $conversation ? $this->evidence($conversation, $reporter, $reported) : null,
            'blocked' => $block || $report->blocked,
        ])->save();

        if ($block) {
            $this->blocks->block($reporter, $reported);
        }

        return $report;
    }

    public function review(UserReport $report, User $admin, string $status, ?string $note): UserReport
    {
        $report->forceFill([
            'status' => $status,
            'admin_note' => ($note = trim((string) $note)) === '' ? null : mb_substr($note, 0, 2000),
            'reviewed_by' => $status === UserReport::STATUS_OPEN ? null : $admin->getKey(),
            'reviewed_at' => $status === UserReport::STATUS_OPEN ? null : now(),
        ])->save();

        return $report;
    }

    /**
     * The reported person's latest messages that the reporter can see.
     *
     * @return list<array{id: int, type: string, preview: string, created_at: ?string}>
     */
    private function evidence(Conversation $conversation, User $reporter, User $reported): array
    {
        return $conversation->messages()
            ->visibleTo($reporter)
            ->where('sender_id', $reported->getKey())
            ->where('deleted_for_everyone', false)
            ->whereNotIn('message_type', [Message::TYPE_SYSTEM, Message::TYPE_CALL])
            ->latest('id')
            ->limit(self::EVIDENCE_MESSAGES)
            ->get()
            ->reverse()
            ->map(fn (Message $message) => [
                'id' => $message->id,
                'type' => $message->message_type,
                'preview' => $message->preview(300),
                'created_at' => $message->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}

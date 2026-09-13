<?php

namespace App\Services;

use App\Events\MessageUpdated;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * M22 — View once: a photo, video or voice message the receiver can open a
 * single time. Opening gives a short-lived private link; a few minutes later
 * the file is removed from the server.
 */
class ViewOnceService
{
    /** How long the opened media can be loaded. */
    public const LINK_MINUTES = 2;

    /** The file is removed this long after opening. */
    public const KEEP_MINUTES = 5;

    public function __construct(private readonly AttachmentService $attachments) {}

    public static function isViewOnce(Message $message): bool
    {
        return (bool) ($message->attachment_meta['view_once'] ?? false);
    }

    /**
     * Open the message (receiver only, once) and return a temporary link to its media.
     *
     * @return array{url: string, message: Message}
     *
     * @throws RuntimeException
     */
    public function open(Message $message, User $user): array
    {
        if (! self::isViewOnce($message) || $message->deleted_for_everyone) {
            throw new RuntimeException('This message is not a view once message.');
        }

        if ($message->isSentBy($user)) {
            throw new RuntimeException('View once messages you sent cannot be opened.');
        }

        if (! empty($message->attachment_meta['opened_at']) || $this->attachments->path($message) === null) {
            throw new RuntimeException('This view once message was already opened.');
        }

        $message->forceFill(['attachment_meta' => ['opened_at' => now()->toIso8601String()] + $message->attachment_meta])->save();
        $message->load(Message::DISPLAY_RELATIONS);

        // The sender sees "Opened".
        broadcast(new MessageUpdated($message))->toOthers();

        return [
            'url' => URL::temporarySignedRoute('messages.view-once.file', now()->addMinutes(self::LINK_MINUTES), $message, false),
            'message' => $message,
        ];
    }

    /**
     * Whether the receiver may still load the media right now.
     */
    public function canLoad(Message $message, User $user): bool
    {
        $openedAt = $message->attachment_meta['opened_at'] ?? null;

        return self::isViewOnce($message)
            && ! $message->isSentBy($user)
            && $openedAt !== null
            && now()->lt(Carbon::parse($openedAt)->addMinutes(self::KEEP_MINUTES));
    }

    /**
     * Remove the files of view once messages opened a while ago.
     */
    public function purge(): int
    {
        $count = 0;

        Message::query()
            ->whereNotNull('attachment')
            ->where('attachment_meta->view_once', true)
            ->whereNotNull('attachment_meta->opened_at')
            ->orderBy('id')
            ->chunkById(200, function ($messages) use (&$count) {
                foreach ($messages as $message) {
                    $openedAt = $message->attachment_meta['opened_at'] ?? null;
                    if (! $openedAt || now()->lt(Carbon::parse($openedAt)->addMinutes(self::KEEP_MINUTES))) {
                        continue;
                    }

                    $this->attachments->delete($message);
                    $message->forceFill(['attachment' => null])->saveQuietly();
                    $count++;
                }
            });

        return $count;
    }
}

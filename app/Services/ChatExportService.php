<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * D7 — Export chat: the messages a person can see in one chat as a text file, or a ZIP with
 * the text and the photos, videos, voice messages and documents. Chat backups (D8) put every
 * chat into one ZIP the same way.
 */
class ChatExportService
{
    private const CHUNK = 500;

    /** Text files added to open ZIPs; removed when the ZIP is closed. */
    private array $pending = [];

    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly ContactService $contacts,
        private readonly ChatCardService $cards,
    ) {}

    public function zipAvailable(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /** Most media bytes one export or backup includes (the rest is listed as left out). */
    public function mediaLimit(): int
    {
        return max(0, (int) config('chat.export.max_media_mb', 512)) * 1024 * 1024;
    }

    public function title(Conversation $conversation, User $user): string
    {
        return $this->cards->for($user, [$conversation->getKey()])[$conversation->getKey()]['name'] ?? 'Chat';
    }

    /** "One2One Chat with Ayesha Khan.txt" (safe on every system). */
    public function filename(Conversation $conversation, User $user, string $extension): string
    {
        $name = trim(preg_replace('/[^\pL\pN ._()\-]+/u', ' ', $this->title($conversation, $user)) ?? '');

        return config('app.name').' with '.Str::limit($name !== '' ? $name : 'chat', 60, '').'.'.$extension;
    }

    /**
     * A text file with the chat; returns its temporary path.
     */
    public function exportText(Conversation $conversation, User $user): string
    {
        $path = $this->temporaryPath('txt');
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('The export file could not be created.');
        }

        try {
            $this->writeChat($handle, $conversation, $user);
        } finally {
            fclose($handle);
        }

        return $path;
    }

    /**
     * A ZIP with "chat.txt" and the media of the chat; returns its temporary path.
     */
    public function exportZip(Conversation $conversation, User $user): string
    {
        $path = $this->temporaryPath('zip');
        $zip = $this->openZip($path);
        $budget = $this->mediaLimit();

        $this->addChat($zip, '', $conversation, $user, $budget);
        $this->closeZip($zip);

        return $path;
    }

    /**
     * Add one chat (text + media under "{folder}media/") to an open ZIP.
     *
     * @param  int  $budget  media bytes still allowed; lowered by what is added
     * @return array{messages: int, media: int, media_bytes: int, left_out: int}
     */
    public function addChat(ZipArchive $zip, string $folder, Conversation $conversation, User $user, int &$budget, bool $withMedia = true): array
    {
        $text = $this->temporaryPath('txt');
        $handle = fopen($text, 'wb');
        if ($handle === false) {
            throw new RuntimeException('The export file could not be created.');
        }

        $media = [];
        try {
            $stats = $this->writeChat($handle, $conversation, $user, ! $withMedia ? null : function (Message $message, string $name) use (&$media, &$budget, $zip, $folder) {
                $file = $this->attachments->path($message);
                $size = $file ? (int) @filesize($file) : 0;
                if (! $file || $size > $budget) {
                    return false;
                }
                $budget -= $size;
                $zip->addFile($file, $folder.'media/'.$name);
                // Media is already compressed.
                $zip->setCompressionName($folder.'media/'.$name, ZipArchive::CM_STORE);
                $media[] = $size;

                return true;
            });
        } finally {
            fclose($handle);
        }

        $zip->addFile($text, $folder.'chat.txt');
        // ZipArchive reads added files when it closes (see closeZip()).
        $this->pending[] = $text;

        return $stats + ['media' => count($media), 'media_bytes' => array_sum($media)];
    }

    public function openZip(string $path): ZipArchive
    {
        if (! $this->zipAvailable()) {
            throw new RuntimeException('ZIP files are not available on this server.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The ZIP file could not be created.');
        }

        return $zip;
    }

    /** Close a ZIP made with addChat() and remove its temporary text files. */
    public function closeZip(ZipArchive $zip): void
    {
        try {
            if (! $zip->close()) {
                throw new RuntimeException('The ZIP file could not be written.');
            }
        } finally {
            foreach ($this->pending as $file) {
                @unlink($file);
            }
            $this->pending = [];
        }
    }

    public function temporaryPath(string $extension): string
    {
        $directory = storage_path('app/exports');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        return $directory.'/'.Str::uuid()->toString().'.'.$extension;
    }

    /**
     * Write the chat as lines like "15/09/2026, 10:35 - Ayesha Khan: Hello".
     *
     * @param  resource  $handle
     * @param  (callable(Message, string): bool)|null  $attach  adds a file; true when it was included
     * @return array{messages: int, left_out: int}
     */
    public function writeChat($handle, Conversation $conversation, User $user, ?callable $attach = null): array
    {
        $timezone = (string) config('app.timezone');
        $exported = now($timezone);
        $title = $this->title($conversation, $user);
        $stats = ['messages' => 0, 'left_out' => 0];

        fwrite($handle, "\u{FEFF}");
        fwrite($handle, "{$title}\n");
        fwrite($handle, 'Exported from '.config('app.name').' on '.$exported->format('d/m/Y, H:i').". Only messages you can see are included.\n\n");

        $isChannel = $conversation->isChannel();
        $channelAdmin = $isChannel && $conversation->isAdmin($user);

        $conversation->messages()
            ->visibleTo($user)
            ->with(['sender:id,name'])
            ->chunkById(self::CHUNK, function (Collection $messages) use ($handle, $user, $attach, $timezone, $conversation, $isChannel, $channelAdmin, &$stats) {
                $saved = $this->contacts->savedNames($user, $messages->pluck('sender_id')->filter()->unique()->values()->all());

                foreach ($messages as $message) {
                    $time = Carbon::parse($message->created_at)->setTimezone($timezone)->format('d/m/Y, H:i');

                    if ($message->message_type === Message::TYPE_SYSTEM) {
                        fwrite($handle, "{$time} - ".$message->systemText()."\n");
                        $stats['messages']++;

                        continue;
                    }

                    $sender = match (true) {
                        $message->isSentBy($user) => $user->name,
                        $isChannel && ! $channelAdmin => $conversation->name ?: 'Channel',
                        default => $saved[$message->sender_id] ?? $message->sender?->name ?? 'Deleted account',
                    };

                    [$text, $leftOut] = $this->body($message, $attach);
                    $stats['left_out'] += $leftOut ? 1 : 0;
                    $stats['messages']++;
                    fwrite($handle, "{$time} - {$sender}: {$text}\n");
                }
            });

        return $stats;
    }

    /**
     * @return array{0: string, 1: bool} [text, media left out]
     */
    private function body(Message $message, ?callable $attach): array
    {
        $caption = trim((string) $message->message);

        if ($message->deleted_for_everyone) {
            return ['This message was deleted', false];
        }

        $meta = $message->attachment_meta ?? [];
        if ($meta['view_once'] ?? false) {
            return ['<View once media omitted>', false];
        }

        return match ($message->message_type) {
            Message::TYPE_TEXT => [$caption, false],
            Message::TYPE_CALL => [ltrim(mb_substr($message->callPreview(), 2)), false],
            Message::TYPE_LOCATION => [
                (($meta['live'] ?? false) ? 'Live location' : 'Location').': https://maps.google.com/?q='.((float) ($meta['lat'] ?? 0)).','.((float) ($meta['lng'] ?? 0)),
                false,
            ],
            Message::TYPE_CONTACT => [
                'Contact card: '.trim(($meta['name'] ?? '').' '.implode(', ', array_map('strval', (array) ($meta['phones'] ?? [])))),
                false,
            ],
            Message::TYPE_POLL => [
                'POLL: '.($meta['question'] ?? '')
                .collect($meta['options'] ?? [])->map(fn ($option) => "\nOPTION: ".($option['text'] ?? ''))->implode(''),
                false,
            ],
            default => $this->mediaLine($message, $caption, $attach),
        };
    }

    /**
     * @return array{0: string, 1: bool}
     */
    private function mediaLine(Message $message, string $caption, ?callable $attach): array
    {
        $label = match ($message->message_type) {
            Message::TYPE_IMAGE => $message->attachment_mime === 'image/gif' ? 'GIF' : 'Photo',
            Message::TYPE_VIDEO => 'Video',
            Message::TYPE_VOICE => 'Voice message',
            Message::TYPE_STICKER => 'Sticker',
            default => 'Document',
        };

        $suffix = $caption !== '' ? " {$caption}" : '';

        if (! $message->attachment) {
            return ["<{$label} omitted>{$suffix}", false];
        }

        $name = $this->mediaName($message, $label);
        if ($attach && $attach($message, $name)) {
            return ["<attached: media/{$name}>{$suffix}", false];
        }

        $document = $message->message_type === Message::TYPE_DOCUMENT && $message->attachment_name ? " ({$message->attachment_name})" : '';

        return ["<{$label} omitted{$document}>{$suffix}", $attach !== null];
    }

    /** "00001234-PHOTO-2026-09-15.jpg", or the document's own name after the number. */
    private function mediaName(Message $message, string $label): string
    {
        $extension = strtolower(pathinfo((string) ($message->attachment_name ?: $message->attachment), PATHINFO_EXTENSION))
            ?: strtolower(pathinfo((string) $message->attachment, PATHINFO_EXTENSION));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'bin';
        $prefix = str_pad((string) $message->id, 8, '0', STR_PAD_LEFT);

        if ($message->message_type === Message::TYPE_DOCUMENT && $message->attachment_name) {
            $base = trim(preg_replace('/[^\pL\pN ._()\-]+/u', '_', pathinfo($message->attachment_name, PATHINFO_FILENAME)) ?? '') ?: 'document';

            return $prefix.'-'.Str::limit($base, 60, '').'.'.$extension;
        }

        return $prefix.'-'.strtoupper(str_replace(' ', '-', $label)).'-'.Carbon::parse($message->created_at)->format('Y-m-d').'.'.$extension;
    }
}

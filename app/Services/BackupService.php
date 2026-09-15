<?php

namespace App\Services;

use App\Jobs\CreateBackup;
use App\Models\ChatBackup;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * D8 — chat backups.
 *
 * Personal: every chat a person can see, as text (and media when chosen), in one ZIP they
 * download from Settings. Their chats stay on the server, so a new phone gets everything
 * back by signing in; the file is a copy to keep.
 *
 * Server: the database (as SQL) and all uploaded files, made from the admin panel, to move
 * or restore the whole app.
 */
class BackupService
{
    public const DISK = 'local';

    /** A backup still "working" after this long has crashed. */
    private const STUCK_MINUTES = 120;

    public function __construct(
        private readonly ChatExportService $exports,
        private readonly ChatCardService $cards,
        private readonly DatabaseDumpService $dumps,
    ) {}

    public function available(): bool
    {
        return $this->exports->zipAvailable();
    }

    public function latestFor(User $user): ?ChatBackup
    {
        return ChatBackup::query()->where('kind', ChatBackup::KIND_PERSONAL)->where('user_id', $user->getKey())->latest('id')->first();
    }

    /** Ask for a personal backup (a backup already being made is returned instead). */
    public function requestPersonal(User $user, bool $withMedia): ChatBackup
    {
        $busy = ChatBackup::query()->where('kind', ChatBackup::KIND_PERSONAL)->where('user_id', $user->getKey())
            ->whereIn('status', [ChatBackup::STATUS_PENDING, ChatBackup::STATUS_WORKING])->first();
        if ($busy) {
            return $busy;
        }

        $backup = ChatBackup::query()->create([
            'kind' => ChatBackup::KIND_PERSONAL,
            'user_id' => $user->getKey(),
            'include_media' => $withMedia,
        ]);

        CreateBackup::dispatch($backup->id);

        return $backup->fresh();
    }

    public function requestServer(User $admin, bool $withFiles = true): ChatBackup
    {
        $busy = ChatBackup::query()->where('kind', ChatBackup::KIND_SERVER)->whereIn('status', [ChatBackup::STATUS_PENDING, ChatBackup::STATUS_WORKING])->first();
        if ($busy) {
            return $busy;
        }

        $backup = ChatBackup::query()->create([
            'kind' => ChatBackup::KIND_SERVER,
            'user_id' => $admin->getKey(),
            'include_media' => $withFiles,
        ]);

        CreateBackup::dispatch($backup->id);

        return $backup->fresh();
    }

    /** Make the file (from the queue, or the scheduler when no worker picked it up). */
    public function run(int $backupId): void
    {
        // Claim it, so a queue worker and the scheduler never both make it.
        $claimed = ChatBackup::query()->whereKey($backupId)->where('status', ChatBackup::STATUS_PENDING)
            ->update(['status' => ChatBackup::STATUS_WORKING, 'started_at' => now(), 'updated_at' => now()]);
        if (! $claimed) {
            return;
        }

        $backup = ChatBackup::query()->findOrFail($backupId);
        @set_time_limit(0);

        try {
            $result = $backup->kind === ChatBackup::KIND_SERVER ? $this->buildServer($backup) : $this->buildPersonal($backup);

            $backup->forceFill($result + [
                'status' => ChatBackup::STATUS_READY,
                'finished_at' => now(),
                'error' => null,
                'expires_at' => $backup->kind === ChatBackup::KIND_PERSONAL ? now()->addDays(max(1, (int) config('chat.backups.keep_days', 7))) : null,
            ])->save();

            $this->cleanUpOlder($backup);
        } catch (Throwable $e) {
            Log::error('Backup failed: '.$e->getMessage(), ['backup_id' => $backupId]);
            $backup->forceFill([
                'status' => ChatBackup::STATUS_FAILED,
                'finished_at' => now(),
                'error' => Str::limit($e->getMessage(), 180),
            ])->save();
        }
    }

    /** Backups nobody's queue worker picked up (run by the scheduler). */
    public function runPending(): int
    {
        $ids = ChatBackup::query()->where('status', ChatBackup::STATUS_PENDING)->where('created_at', '<=', now()->subMinute())->orderBy('id')->limit(3)->pluck('id');
        $ids->each(fn ($id) => $this->run((int) $id));

        return $ids->count();
    }

    /** Remove expired personal backups, mark crashed ones failed, and clear old export files. */
    public function prune(): int
    {
        $count = 0;
        ChatBackup::query()->where('kind', ChatBackup::KIND_PERSONAL)->where('expires_at', '<=', now())->whereNotNull('path')->get()
            ->each(function (ChatBackup $backup) use (&$count) {
                $this->deleteFile($backup);
                $backup->forceFill(['path' => null])->save();
                $count++;
            });

        ChatBackup::query()->where('status', ChatBackup::STATUS_WORKING)->where('started_at', '<=', now()->subMinutes(self::STUCK_MINUTES))
            ->update(['status' => ChatBackup::STATUS_FAILED, 'error' => 'The backup stopped before it finished. Try again.', 'finished_at' => now()]);

        foreach (glob(storage_path('app/exports/*')) ?: [] as $file) {
            if (is_file($file) && filemtime($file) < now()->subDay()->getTimestamp()) {
                @unlink($file);
            }
        }

        return $count;
    }

    public function delete(ChatBackup $backup): void
    {
        $this->deleteFile($backup);
        $backup->delete();
    }

    public function absolutePath(ChatBackup $backup): ?string
    {
        $disk = Storage::disk(self::DISK);

        return $backup->path && $disk->exists($backup->path) ? $disk->path($backup->path) : null;
    }

    public function downloadName(ChatBackup $backup): string
    {
        $date = ($backup->finished_at ?? $backup->created_at)->format('Y-m-d-His');

        return $backup->kind === ChatBackup::KIND_SERVER
            ? Str::slug(config('app.name'))."-server-backup-{$date}.zip"
            : config('app.name')." chat backup {$date}.zip";
    }

    /* ------------------------------------------------------------------ */

    /** @return array{path: string, size: int, stats: array<string, int>} */
    private function buildPersonal(ChatBackup $backup): array
    {
        $user = $backup->user ?? throw new \RuntimeException('The account no longer exists.');
        $relative = "backups/users/{$user->getKey()}/".Str::uuid()->toString().'.zip';
        $path = $this->prepare($relative);

        $zip = $this->exports->openZip($path);
        $budget = $backup->include_media ? $this->exports->mediaLimit() : 0;
        $stats = ['chats' => 0, 'messages' => 0, 'media' => 0, 'left_out' => 0];

        $conversationIds = Message::query()->visibleTo($user)->distinct()->pluck('conversation_id')->map(fn ($id) => (int) $id)->all();
        $cards = $this->cards->for($user, $conversationIds);
        $used = [];

        foreach (Conversation::query()->whereKey($conversationIds)->orderBy('id')->cursor() as $conversation) {
            $name = trim(preg_replace('/[^\pL\pN ._()\-]+/u', ' ', $cards[$conversation->id]['name'] ?? 'Chat') ?? '') ?: 'Chat';
            $folder = Str::limit($name, 50, '');
            // Two chats with the same name get their own folders.
            $folder = isset($used[mb_strtolower($folder)]) ? "{$folder} ({$conversation->id})" : $folder;
            $used[mb_strtolower($folder)] = true;

            $result = $this->exports->addChat($zip, "chats/{$folder}/", $conversation, $user, $budget, $backup->include_media);
            $stats['chats']++;
            $stats['messages'] += $result['messages'];
            $stats['media'] += $result['media'];
            $stats['left_out'] += $result['left_out'];
        }

        $zip->addFromString('README.txt', $this->readme($user, $stats, $backup->include_media));
        $this->exports->closeZip($zip);

        return ['path' => $relative, 'size' => (int) filesize($path), 'stats' => $stats];
    }

    /** @return array{path: string, size: int, stats: array<string, int>} */
    private function buildServer(ChatBackup $backup): array
    {
        $relative = 'backups/server/'.now()->format('Y-m-d_His').'-'.Str::random(6).'.zip';
        $path = $this->prepare($relative);

        $sql = $this->exports->temporaryPath('sql');
        $zip = $this->exports->openZip($path);

        try {
            $tables = $this->dumps->dump($sql);
            $zip->addFile($sql, 'database.sql');

            $files = 0;
            $bytes = 0;
            if ($backup->include_media) {
                foreach (['chat' => 'files/chat/', 'public' => 'files/public/'] as $diskName => $folder) {
                    $disk = Storage::disk($diskName);
                    foreach ($disk->allFiles() as $file) {
                        if (str_starts_with($file, '.') || str_contains($file, '/.')) {
                            continue;
                        }
                        $zip->addFile($disk->path($file), $folder.$file);
                        $zip->setCompressionName($folder.$file, ZipArchive::CM_STORE);
                        $files++;
                        $bytes += (int) @filesize($disk->path($file));
                    }
                }
            }

            $zip->addFromString('RESTORE.txt', $this->restoreGuide());
            $this->exports->closeZip($zip);
        } finally {
            @unlink($sql);
        }

        return ['path' => $relative, 'size' => (int) filesize($path), 'stats' => ['tables' => $tables, 'files' => $files, 'file_bytes' => $bytes]];
    }

    private function prepare(string $relative): string
    {
        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory(dirname($relative));

        return $disk->path($relative);
    }

    private function deleteFile(ChatBackup $backup): void
    {
        if ($backup->path) {
            Storage::disk(self::DISK)->delete($backup->path);
        }
    }

    /** One personal backup per person; server backups keep the newest few. */
    private function cleanUpOlder(ChatBackup $backup): void
    {
        $older = ChatBackup::query()->where('kind', $backup->kind)->where('id', '!=', $backup->id)
            ->where('status', '!=', ChatBackup::STATUS_WORKING)->where('status', '!=', ChatBackup::STATUS_PENDING)
            ->when($backup->kind === ChatBackup::KIND_PERSONAL, fn ($q) => $q->where('user_id', $backup->user_id))
            ->orderByDesc('id')
            ->get();

        $keep = $backup->kind === ChatBackup::KIND_SERVER ? max(1, (int) config('chat.backups.server_keep', 5)) - 1 : 0;
        $older->slice($keep)->each(fn (ChatBackup $old) => $this->delete($old));
    }

    /** @param array<string, int> $stats */
    private function readme(User $user, array $stats, bool $withMedia): string
    {
        return implode("\n", [
            config('app.name').' chat backup',
            'Account: '.$user->name.($user->phone ? " ({$user->phone})" : ''),
            'Made on: '.now()->format('d/m/Y H:i').' ('.config('app.timezone').')',
            '',
            "Chats: {$stats['chats']}   Messages: {$stats['messages']}".($withMedia ? "   Media files: {$stats['media']}" : ''),
            $withMedia && $stats['left_out'] > 0 ? "{$stats['left_out']} media file(s) were too large for this backup or no longer available." : '',
            '',
            'Each chat has its own folder with chat.txt'.($withMedia ? ' and a media folder.' : '.'),
            'Only messages you could see are included. Keep this file safe: anyone who has it can read your chats.',
            '',
            'Moving to a new phone?',
            'Your chats are kept on the server. Install '.config('app.name').' on the new phone and sign in with the same',
            'mobile number or email - all your chats, groups and media come back by themselves. You do not need this file for that.',
            '',
        ]);
    }

    private function restoreGuide(): string
    {
        $chatRoot = str_replace('\\', '/', (string) config('filesystems.disks.chat.root'));
        $publicRoot = str_replace('\\', '/', (string) config('filesystems.disks.public.root'));

        return implode("\n", [
            config('app.name').' server backup - '.now()->format('Y-m-d H:i'),
            '',
            'Contents',
            '  database.sql     the whole MySQL database (tables and rows)',
            '  files/chat/      private uploads (chat photos, videos, voice messages, documents, statuses, stickers, wallpapers)',
            '  files/public/    public files (profile photos, group icons)',
            '',
            'Restore on a new server',
            '  1. Install the app from Git as usual (docs/DEPLOYMENT-AAPANEL.md) and create an empty database.',
            '  2. Put the SAME APP_KEY in .env as the old server. Without it, saved passwords and keys in',
            '     Admin -> App settings cannot be read, and everybody has to sign in again.',
            '  3. Import the database:  mysql -u DB_USER -p DB_NAME < database.sql',
            "  4. Copy files/chat/* to {$chatRoot}/",
            "     and files/public/* to {$publicRoot}/",
            '  5. Run: php artisan storage:link && php artisan migrate --force && php artisan optimize',
            '  6. Make sure the storage folder is writable by the web server user.',
            '',
            'This file contains password hashes and private chats. Keep it somewhere only administrators can reach.',
            '',
        ]);
    }
}

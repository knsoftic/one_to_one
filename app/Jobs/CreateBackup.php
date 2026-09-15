<?php

namespace App\Jobs;

use App\Services\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * D8 — make a backup file in the background. When no queue worker runs, the scheduler
 * (`chat:backups`) makes waiting backups instead.
 */
class CreateBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $backupId) {}

    public function handle(BackupService $backups): void
    {
        $backups->run($this->backupId);
    }
}

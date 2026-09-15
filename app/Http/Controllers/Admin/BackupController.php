<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatBackup;
use App\Services\AdminAuditService;
use App\Services\BackupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * D8 — Admin panel → Backups: the whole database and all uploaded files in one ZIP.
 */
class BackupController extends Controller
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly AdminAuditService $audit,
    ) {}

    public function index(): View
    {
        $backups = ChatBackup::query()->where('kind', ChatBackup::KIND_SERVER)->with('user:id,name')->latest('id')->limit(20)->get();

        return view('admin.backups.index', [
            'backups' => $backups,
            'available' => $this->backups->available(),
            'busy' => $backups->contains(fn (ChatBackup $backup) => $backup->isBusy()),
            'personal' => [
                'ready' => ChatBackup::query()->where('kind', ChatBackup::KIND_PERSONAL)->where('status', ChatBackup::STATUS_READY)->whereNotNull('path')->count(),
                'bytes' => (int) ChatBackup::query()->where('kind', ChatBackup::KIND_PERSONAL)->whereNotNull('path')->sum('size'),
            ],
            'keep' => max(1, (int) config('chat.backups.server_keep', 5)),
            'keepDays' => max(1, (int) config('chat.backups.keep_days', 7)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($this->backups->available(), 422, 'Backups need the PHP zip extension.');
        $withFiles = $request->boolean('files', true);

        $backup = $this->backups->requestServer($request->user(), $withFiles);
        $this->audit->record($request->user(), 'backup.created', $backup, $withFiles ? 'Made a server backup (database and files)' : 'Made a server backup (database only)');

        return redirect()->route('admin.backups')->with('status', $backup->isReady()
            ? 'The backup is ready to download.'
            : 'The backup is being made. This page shows it when it is ready.');
    }

    public function download(Request $request, ChatBackup $backup): BinaryFileResponse
    {
        abort_unless($backup->kind === ChatBackup::KIND_SERVER, 404);
        $path = $backup->status === ChatBackup::STATUS_READY ? $this->backups->absolutePath($backup) : null;
        abort_if($path === null, 404);

        $this->audit->record($request->user(), 'backup.downloaded', $backup, 'Downloaded a server backup');

        return response()->download($path, $this->backups->downloadName($backup), [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroy(Request $request, ChatBackup $backup): RedirectResponse
    {
        abort_unless($backup->kind === ChatBackup::KIND_SERVER, 404);
        abort_if($backup->status === ChatBackup::STATUS_WORKING, 409, 'This backup is still being made.');

        $this->audit->record($request->user(), 'backup.deleted', null, 'Deleted a server backup from '.$backup->created_at?->format('j M Y H:i'));
        $this->backups->delete($backup);

        return redirect()->route('admin.backups')->with('status', 'Backup deleted.');
    }
}

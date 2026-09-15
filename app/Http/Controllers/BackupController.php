<?php

namespace App\Http\Controllers;

use App\Models\ChatBackup;
use App\Services\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * D8 — Settings → Storage and data → Chat backup: a ZIP of all my chats to keep.
 */
class BackupController extends Controller
{
    public function __construct(private readonly BackupService $backups) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'available' => $this->backups->available(),
            'backup' => $this->backups->latestFor($request->user())?->toPayload(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($this->backups->available(), 422, 'Backups are not available on this server.');
        $validated = $request->validate(['media' => ['sometimes', 'boolean']]);

        $backup = $this->backups->requestPersonal($request->user(), (bool) ($validated['media'] ?? false));

        return response()->json(['backup' => $backup->fresh()->toPayload()], 202);
    }

    public function download(Request $request, ChatBackup $backup): BinaryFileResponse
    {
        $this->authorizeOwner($request, $backup);
        $path = $backup->isReady() ? $this->backups->absolutePath($backup) : null;
        abort_if($path === null, 404);

        return response()->download($path, $this->backups->downloadName($backup), [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroy(Request $request, ChatBackup $backup): JsonResponse
    {
        $this->authorizeOwner($request, $backup);
        abort_if($backup->status === ChatBackup::STATUS_WORKING, 409, 'This backup is still being made.');

        $this->backups->delete($backup);

        return response()->json(['message' => 'Backup deleted.']);
    }

    private function authorizeOwner(Request $request, ChatBackup $backup): void
    {
        abort_unless($backup->kind === ChatBackup::KIND_PERSONAL && (int) $backup->user_id === (int) $request->user()->getKey(), 404);
    }
}

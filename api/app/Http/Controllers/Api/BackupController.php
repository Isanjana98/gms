<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BackupLog;
use App\Services\BackupService;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** SRS 30 - Backups. */
class BackupController extends Controller
{
    public function __construct(private readonly BackupService $backups) {}

    /** GET /api/backups */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::BACKUPS_MANAGE), 403);

        $logs = BackupLog::query()
            ->orderByDesc('started_at')
            ->paginate($this->perPage($request, 30));

        return response()->json($logs->through(fn (BackupLog $log) => [
            'id' => $log->id,
            'type' => $log->type,
            'filename' => $log->filename,
            'size_mb' => $log->size_mb,
            'status' => $log->status,
            'message' => $log->message,
            'started_at' => $log->started_at?->toIso8601String(),
            'finished_at' => $log->finished_at?->toIso8601String(),
            // A log row can outlive its file if someone cleared the disk.
            'downloadable' => $log->status === 'SUCCESS' && $log->path && file_exists($log->path),
        ]));
    }

    /** POST /api/backups - run one now. */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::BACKUPS_MANAGE), 403);

        $log = $this->backups->run('MANUAL');

        return response()->json([
            'message' => $log->status === 'SUCCESS'
                ? "Backup complete: {$log->filename} ({$log->size_mb} MB)."
                : "Backup failed: {$log->message}",
            'data' => [
                'id' => $log->id,
                'filename' => $log->filename,
                'status' => $log->status,
                'size_mb' => $log->size_mb,
            ],
        ], $log->status === 'SUCCESS' ? 201 : 500);
    }

    /** GET /api/backups/{backupLog}/download */
    public function download(Request $request, BackupLog $backupLog): BinaryFileResponse
    {
        abort_unless($request->user()->can(Permissions::BACKUPS_MANAGE), 403);
        abort_unless($backupLog->path && file_exists($backupLog->path), 404, 'This backup file is no longer on disk.');

        return response()->download($backupLog->path, $backupLog->filename);
    }

    /** DELETE /api/backups/{backupLog} */
    public function destroy(Request $request, BackupLog $backupLog): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::BACKUPS_MANAGE), 403);

        if ($backupLog->path && file_exists($backupLog->path)) {
            @unlink($backupLog->path);
        }

        $backupLog->delete();

        return $this->ok('Backup deleted.');
    }
}

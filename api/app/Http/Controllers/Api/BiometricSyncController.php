<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceSyncLog;
use App\Models\BiometricDevice;
use App\Services\Biometric\BiometricSyncService;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** SRS 13 / 24 / 29 - Synchronisation endpoints. */
class BiometricSyncController extends Controller
{
    public function __construct(private readonly BiometricSyncService $sync) {}

    /**
     * POST /api/biometric/sync
     *
     * SRS 13 - The manual "Sync Attendance" button. Runs inline rather than
     * queueing, because the administrator pressed it to find out whether the
     * device is working and needs the answer, not a job id.
     */
    public function attendance(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::DEVICES_SYNC), 403);

        $data = $request->validate([
            'device_id' => ['nullable', 'integer', 'exists:biometric_devices,id'],
        ]);

        $devices = isset($data['device_id'])
            ? BiometricDevice::whereKey($data['device_id'])->get()
            : BiometricDevice::query()->visibleTo($request->user())->pollable()->get();

        if ($devices->isEmpty()) {
            return response()->json(['message' => 'No devices are configured for attendance pulling.'], 422);
        }

        $results = $devices->map(function (BiometricDevice $device) {
            $log = $this->sync->syncAttendance($device, 'MANUAL');

            return [
                'device_id' => $device->id,
                'device' => $device->name,
                'status' => $log->status,
                'fetched' => $log->records_fetched,
                'new' => $log->records_new,
                'duplicate' => $log->records_duplicate,
                'unmatched' => $log->records_unmatched,
                'rejected' => $log->records_rejected,
                'failed' => $log->records_failed,
                'message' => $log->message,
                'duration_ms' => $log->duration_ms,
            ];
        });

        $newRecords = $results->sum('new');

        return response()->json([
            'message' => $newRecords > 0
                ? "Synchronised {$newRecords} new attendance record(s)."
                : 'Synchronisation complete. No new attendance records.',
            'data' => $results->all(),
        ]);
    }

    /** POST /api/biometric/users/sync - SRS 24, both directions. */
    public function users(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::DEVICES_SYNC), 403);

        $data = $request->validate([
            'device_id' => ['required', 'integer', 'exists:biometric_devices,id'],
            'direction' => ['required', 'in:up,down'],
        ]);

        $device = BiometricDevice::findOrFail($data['device_id']);

        $log = $data['direction'] === 'down'
            ? $this->sync->syncUsersDown($device)
            : $this->sync->syncUsersUp($device);

        return response()->json([
            'message' => $log->message ?? 'User synchronisation complete.',
            'status' => $log->status,
            'fetched' => $log->records_fetched,
            'succeeded' => $log->records_new,
            'unmatched' => $log->records_unmatched,
            'failed' => $log->records_failed,
        ], $log->status === 'FAILED' ? 422 : 200);
    }

    /** GET /api/biometric/sync-logs - SRS 13 synchronisation status and errors. */
    public function logs(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::DEVICES_VIEW), 403);

        $logs = AttendanceSyncLog::query()
            ->when($request->filled('device_id'), fn ($q) => $q->where('device_id', $request->integer('device_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->boolean('failed_only'), fn ($q) => $q->failed())
            ->with(['device:id,name,code', 'triggeredBy:id,name'])
            ->orderByDesc('started_at')
            ->paginate($this->perPage($request, 50));

        return response()->json([
            'data' => $logs->through(fn (AttendanceSyncLog $log) => [
                'id' => $log->id,
                'device_id' => $log->device_id,
                'device' => $log->device?->name,
                'trigger' => $log->trigger,
                'type' => $log->type,
                'status' => $log->status,
                'window_from' => $log->window_from?->toIso8601String(),
                'window_to' => $log->window_to?->toIso8601String(),
                'records_fetched' => $log->records_fetched,
                'records_new' => $log->records_new,
                'records_duplicate' => $log->records_duplicate,
                'records_unmatched' => $log->records_unmatched,
                'records_rejected' => $log->records_rejected,
                'records_failed' => $log->records_failed,
                'message' => $log->message,
                'started_at' => $log->started_at?->toIso8601String(),
                'finished_at' => $log->finished_at?->toIso8601String(),
                'duration_ms' => $log->duration_ms,
                'triggered_by' => $log->triggeredBy?->name,
            ])->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * GET /api/biometric/sync-status
     *
     * SRS 13 - Last successful sync per device, plus whether it is overdue.
     * This is the screen that answers "is attendance actually being collected?"
     */
    public function status(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::DEVICES_VIEW), 403);

        $interval = (int) config('gms.biometric.sync_interval_minutes', 1);
        // Allow several missed cycles before crying wolf: one slow poll on a
        // busy network is not an outage.
        $staleAfter = now()->subMinutes(max(5, $interval * 5));

        $devices = BiometricDevice::query()
            ->visibleTo($request->user())
            ->active()
            ->get()
            ->map(fn (BiometricDevice $device) => [
                'device_id' => $device->id,
                'name' => $device->name,
                'code' => $device->code,
                'ip_address' => $device->ip_address,
                'mode' => $device->mode->value,
                'sync_mode' => $device->sync_mode,
                'connection_status' => $device->connection_status,
                'last_sync_at' => $device->last_sync_at?->toIso8601String(),
                'last_event_at' => $device->last_event_at?->toIso8601String(),
                'last_error' => $device->last_error,
                'consecutive_failures' => $device->consecutive_failures,
                'is_stale' => $device->last_sync_at === null || $device->last_sync_at->lessThan($staleAfter),
            ]);

        return response()->json([
            'sync_enabled' => (bool) config('gms.biometric.sync_enabled', true),
            'interval_minutes' => $interval,
            'devices' => $devices->all(),
            'healthy' => $devices->every(fn ($d) => ! $d['is_stale'] && $d['connection_status'] !== 'ERROR'),
        ]);
    }
}

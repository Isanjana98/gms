<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** SRS 25 - Reading the audit trail. Read-only by design: there is no write endpoint. */
class AuditLogController extends Controller
{
    /** GET /api/audit-logs */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::AUDIT_VIEW), 403);

        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $logs = AuditLog::query()
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('event'), fn ($q) => $q->where('event', $request->string('event')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('record_type'), fn ($q) => $q->where('auditable_type', 'App\\Models\\'.$request->string('record_type')))
            ->when($request->filled('record_id'), fn ($q) => $q->where('auditable_id', $request->integer('record_id')))
            ->when($request->filled('search'), fn ($q) => $q->where('description', 'like', '%'.$request->string('search').'%'))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date('to')))
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request, 50));

        return response()->json($logs->through(fn (AuditLog $log) => [
            'id' => $log->id,
            'user_id' => $log->user_id,
            'user_name' => $log->user_name,
            'user_role' => $log->user_role,
            'branch_id' => $log->branch_id,
            'event' => $log->event,
            'record_type' => $log->record_type,
            'record_id' => $log->auditable_id,
            'description' => $log->description,
            'old_values' => $log->old_values,
            'new_values' => $log->new_values,
            'ip_address' => $log->ip_address,
            'url' => $log->url,
            'method' => $log->method,
            'created_at' => $log->created_at?->toIso8601String(),
        ]));
    }

    /**
     * GET /api/audit-logs/record/{type}/{id}
     *
     * The history of one record - what reception reaches for when a member
     * disputes a change to their membership.
     */
    public function forRecord(Request $request, string $type, int $id): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::AUDIT_VIEW), 403);

        $class = 'App\\Models\\'.str_replace(['\\', '/', '.'], '', $type);

        abort_unless(class_exists($class), 404, 'Unknown record type.');

        $logs = AuditLog::query()
            ->forRecord($class, $id)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return response()->json(['data' => $logs]);
    }

    /** GET /api/audit-logs/events - the distinct event names, for the filter list. */
    public function events(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::AUDIT_VIEW), 403);

        return response()->json([
            'events' => AuditLog::query()->distinct()->orderBy('event')->pluck('event'),
        ]);
    }
}

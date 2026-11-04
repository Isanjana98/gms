<?php

namespace App\Http\Controllers\Api;

use App\Enums\AttendanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceResource;
use App\Models\Attendance;
use App\Models\Member;
use App\Services\Attendance\AttendanceService;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/** SRS 12 / 16 / 29 - Attendance. */
class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    /** GET /api/attendance */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $records = Attendance::query()
            ->visibleTo($request->user())
            ->forBranch($this->branchScope($request))
            ->when($request->filled('member_id'), fn ($q) => $q->where('member_id', $request->integer('member_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('attendance_type'), fn ($q) => $q->where('attendance_type', $request->string('attendance_type')))
            ->when($request->filled('device_id'), fn ($q) => $q->where(function ($w) use ($request) {
                $w->where('check_in_device_id', $request->integer('device_id'))
                    ->orWhere('check_out_device_id', $request->integer('device_id'));
            }))
            ->when($request->filled('trainer_id'), fn ($q) => $q->whereHas('member', fn ($m) => $m->where('trainer_id', $request->integer('trainer_id'))))
            ->when($request->boolean('invalid_only'), fn ($q) => $q->where('membership_valid', false))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('attendance_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('attendance_date', '<=', $request->date('to')))
            // Neither date given: default to today rather than the whole
            // history, which at SRS 30 scale is millions of rows.
            ->when(! $request->filled('from') && ! $request->filled('to'), fn ($q) => $q->onDate(today()->toDateString()))
            ->with(['member:id,full_name,membership_number,photo_path,status', 'checkInDevice:id,name,code'])
            ->orderByDesc('check_in_time')
            ->paginate($this->perPage($request));

        return AttendanceResource::collection($records);
    }

    /**
     * POST /api/attendance
     *
     * Manual check-in/out from the reception desk - the fallback for a member
     * who forgot to scan or whose finger will not read (SRS 12).
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::ATTENDANCE_MANAGE), 403);

        $data = $request->validate([
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'direction' => ['nullable', 'in:IN,OUT'],
            'time' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $member = Member::findOrFail($data['member_id']);
        $time = isset($data['time']) ? Carbon::parse($data['time']) : now();

        $outcome = ($data['direction'] ?? null) === 'OUT'
            ? $this->attendance->manualCheckOut($member, $time)
            : $this->attendance->manualCheckIn($member, $time, $data['notes'] ?? null);

        $outcome->attendance?->load('member:id,full_name,membership_number,photo_path');

        return response()->json([
            'message' => $outcome->message,
            'accepted' => $outcome->accepted,
            'result' => $outcome->status->value,
            'data' => $outcome->attendance ? new AttendanceResource($outcome->attendance) : null,
        ], $outcome->accepted ? 201 : 200);
    }

    /** GET /api/attendance/member/{member} - SRS 16 member attendance history. */
    public function forMember(Request $request, Member $member): AnonymousResourceCollection
    {
        $records = Attendance::query()
            ->where('member_id', $member->getKey())
            ->when($request->filled('from'), fn ($q) => $q->whereDate('attendance_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('attendance_date', '<=', $request->date('to')))
            ->with('checkInDevice:id,name,code')
            ->orderByDesc('check_in_time')
            ->paginate($this->perPage($request, 50));

        return AttendanceResource::collection($records);
    }

    /**
     * GET /api/attendance/live
     *
     * Who is in the building right now - the screen reception keeps open.
     */
    public function live(Request $request): JsonResponse
    {
        $open = Attendance::query()
            ->visibleTo($request->user())
            ->forBranch($this->branchScope($request))
            ->open()
            ->with('member:id,full_name,membership_number,photo_path')
            ->orderByDesc('check_in_time')
            ->limit(200)
            ->get();

        return response()->json([
            'count' => $open->count(),
            'data' => AttendanceResource::collection($open),
        ]);
    }

    /**
     * GET /api/attendance/validate/{member}
     *
     * Answers "would this member be let in right now?" without recording
     * anything - used by the check-in screen before the member scans.
     */
    public function validateEntry(Member $member): JsonResponse
    {
        $check = $this->attendance->validateEntry($member);
        $open = $this->attendance->openVisit($member);

        return response()->json([
            'valid' => $check->valid,
            'message' => $check->message,
            'currently_checked_in' => $open !== null,
            'checked_in_at' => $open?->check_in_time?->toIso8601String(),
            'member' => [
                'id' => $member->id,
                'full_name' => $member->full_name,
                'membership_number' => $member->membership_number,
                'status' => $member->status->value,
            ],
            'membership' => $check->membership ? [
                'reference' => $check->membership->reference,
                'plan_name' => $check->membership->plan_name,
                'end_date' => $check->membership->end_date->toDateString(),
                'days_remaining' => $check->membership->days_remaining,
            ] : null,
        ]);
    }

    /** PUT /api/attendance/{attendance} - correct a mis-recorded visit (SRS 25 audits it). */
    public function update(Request $request, Attendance $attendance): AttendanceResource
    {
        abort_unless($request->user()->can(Permissions::ATTENDANCE_MANAGE), 403);

        $data = $request->validate([
            'check_in_time' => ['nullable', 'date'],
            'check_out_time' => ['nullable', 'date', 'after:check_in_time'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $attendance->fill($data);

        if ($attendance->check_out_time) {
            $attendance->status = AttendanceStatus::COMPLETED;
        }

        $attendance->recalculateDuration();
        $attendance->updated_by = $request->user()->id;
        $attendance->save();

        return new AttendanceResource($attendance->load('member:id,full_name,membership_number'));
    }

    /** DELETE /api/attendance/{attendance} */
    public function destroy(Request $request, Attendance $attendance): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::ATTENDANCE_MANAGE), 403);

        $attendance->delete();

        return $this->ok('Attendance record removed.');
    }
}

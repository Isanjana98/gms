<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Member;
use App\Models\Payment;
use App\Models\TimetableEntry;
use App\Services\ReceiptRenderer;
use App\Services\SiteContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What a member can see about themselves.
 *
 * Every query here is scoped to `$request->user()` — the member holding the
 * token — and none of them take an id from the request. That is the whole
 * security model of this controller, and it is worth keeping: the moment one
 * endpoint accepts "which member?" as a parameter, every endpoint has to be
 * re-audited for whether it checks the answer.
 */
class MemberPortalController extends Controller
{
    /** GET /api/member/attendance — their own visit history. */
    public function attendance(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $visits = Attendance::query()
            ->where('member_id', $member->getKey())
            ->orderByDesc('check_in_time')
            ->paginate($this->perPage($request, 30, 100));

        return response()->json([
            'data' => $visits->getCollection()->map(fn (Attendance $visit) => [
                'id' => $visit->id,
                'date' => $visit->attendance_date?->toDateString(),
                'check_in_time' => $visit->check_in_time?->toIso8601String(),
                'check_out_time' => $visit->check_out_time?->toIso8601String(),
                'duration_minutes' => $visit->duration_minutes,
                'status' => $visit->status?->value,
            ])->all(),
            'meta' => [
                'current_page' => $visits->currentPage(),
                'last_page' => $visits->lastPage(),
                'total' => $visits->total(),
            ],
            'summary' => $this->attendanceSummary($member),
        ]);
    }

    /** GET /api/member/payments — their own receipts. */
    public function payments(Request $request): JsonResponse
    {
        $member = $this->member($request);

        $payments = Payment::query()
            ->where('member_id', $member->getKey())
            ->completed()
            ->orderByDesc('paid_at')
            ->paginate($this->perPage($request, 30, 100));

        return response()->json([
            'data' => $payments->getCollection()->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'receipt_number' => $payment->receipt_number,
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'amount' => (float) $payment->amount,
                'method' => $payment->method?->label(),
                'payment_type' => $payment->payment_type?->label(),
                'is_refund' => $payment->is_refund,
                'status' => $payment->status,
            ])->all(),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'total' => $payments->total(),
            ],
        ]);
    }

    /**
     * GET /api/member/receipts/{payment} — the PDF of one of their receipts.
     *
     * Scoped by member_id in the lookup rather than checked afterwards, so a
     * receipt belonging to somebody else is simply not found. A 404 and a 403
     * are the same to the app and the 404 says less.
     */
    public function receipt(Request $request, int $payment, ReceiptRenderer $receipts)
    {
        $member = $this->member($request);

        $record = Payment::query()
            ->where('member_id', $member->getKey())
            ->whereKey($payment)
            ->first();

        abort_if($record === null, 404, 'That receipt does not exist.');

        return $receipts->render($record);
    }

    /**
     * GET /api/member/timetable — the published class timetable.
     *
     * The same rows the public website shows, so a member sees one timetable
     * rather than two that can disagree.
     */
    public function timetable(Request $request): JsonResponse
    {
        $entries = TimetableEntry::query()
            ->with('trainer:id,full_name')
            ->active()
            ->get()
            ->map(fn (TimetableEntry $entry) => [
                'id' => $entry->id,
                'day_of_week' => $entry->day_of_week,
                'day_name' => $entry->day_name,
                'class_name' => $entry->class_name,
                'start_time' => mb_substr((string) $entry->start_time, 0, 5),
                'end_time' => mb_substr((string) $entry->end_time, 0, 5),
                'time_label' => $entry->time_label,
                'room' => $entry->room,
                'instructor' => $entry->instructor,
            ]);

        return response()->json([
            'data' => $entries->groupBy('day_of_week'),
            'days' => TimetableEntry::DAYS,
        ]);
    }

    /**
     * GET /api/member/gym — the gym's own details, for the app's about screen.
     *
     * Read from branding and branches, the same source the website uses.
     */
    public function gym(Request $request, SiteContent $content): JsonResponse
    {
        $branding = $content->branding();
        $branch = $this->member($request)->branch ?? $content->primaryBranch();

        return response()->json([
            'name' => $branding['gym_name'],
            'tagline' => $branding['tagline'],
            'logo_url' => $branding['logo_url'],
            'opening_hours' => $content->decoded('website.opening_hours'),
            'branch' => $branch ? [
                'name' => $branch->name,
                'address' => $branch->address,
                'city' => $branch->city,
                'telephone' => $branch->telephone,
                'email' => $branch->email,
            ] : null,
        ]);
    }

    /**
     * Visit statistics the app shows above the history.
     *
     * @return array<string, mixed>
     */
    private function attendanceSummary(Member $member): array
    {
        $row = Attendance::query()
            ->where('member_id', $member->getKey())
            ->selectRaw('COUNT(*) AS visits')
            ->selectRaw('AVG(duration_minutes) AS avg_minutes')
            ->selectRaw('MAX(check_in_time) AS last_visit')
            ->first();

        $thisMonth = Attendance::query()
            ->where('member_id', $member->getKey())
            ->whereBetween('attendance_date', [today()->startOfMonth(), today()])
            ->count();

        return [
            'total_visits' => (int) ($row->visits ?? 0),
            'this_month' => $thisMonth,
            'avg_duration_minutes' => $row?->avg_minutes === null ? null : (int) round((float) $row->avg_minutes),
            'last_visit' => $row->last_visit ?? null,
        ];
    }

    private function member(Request $request): Member
    {
        /** @var Member $member */
        $member = $request->user();

        return $member;
    }
}

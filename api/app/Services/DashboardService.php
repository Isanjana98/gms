<?php

namespace App\Services;

use App\Enums\MembershipStatus;
use App\Enums\MemberStatus;
use App\Models\Attendance;
use App\Models\Member;
use App\Models\Membership;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** SRS 15 - The numbers on the home screen. */
class DashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(?int $branchId = null): array
    {
        $today = today();
        $expiringDays = (int) config('gms.membership.expiring_soon_days', 30);

        return [
            'generated_at' => now()->toIso8601String(),
            'branch_id' => $branchId,
            'members' => [
                'total' => Member::query()->forBranch($branchId)->count(),
                'active' => Member::query()->forBranch($branchId)->active()->count(),
                'expired' => Member::query()->forBranch($branchId)->where('status', MemberStatus::EXPIRED)->count(),
                'new_this_month' => Member::query()->forBranch($branchId)
                    ->whereBetween('join_date', [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()])
                    ->count(),
                'new_today' => Member::query()->forBranch($branchId)->whereDate('join_date', $today)->count(),
            ],
            'attendance' => [
                'today' => Attendance::query()->forBranch($branchId)->onDate($today->toDateString())->count(),
                'currently_in' => Attendance::query()->forBranch($branchId)->open()->count(),
                'yesterday' => Attendance::query()->forBranch($branchId)
                    ->onDate($today->copy()->subDay()->toDateString())->count(),
            ],
            'revenue' => [
                'today' => $this->revenue($branchId, $today, $today),
                'this_month' => $this->revenue($branchId, $today->copy()->startOfMonth(), $today->copy()->endOfMonth()),
                'outstanding' => round((float) Membership::query()
                    ->forBranch($branchId)
                    ->whereIn('status', [MembershipStatus::ACTIVE, MembershipStatus::PENDING])
                    ->sum(DB::raw('final_amount - paid_amount')), 2),
            ],
            'memberships' => [
                'active' => Membership::query()->forBranch($branchId)->active()->count(),
                'pending_payment' => Membership::query()->forBranch($branchId)
                    ->where('status', MembershipStatus::PENDING)->count(),
                'expiring_soon' => Membership::query()->forBranch($branchId)
                    ->active()
                    ->whereBetween('end_date', [$today, $today->copy()->addDays($expiringDays)])
                    ->count(),
            ],
        ];
    }

    /** SRS 15 - Recent check-ins for the live feed. */
    public function recentCheckIns(?int $branchId = null, int $limit = 10): Collection
    {
        return Attendance::query()
            ->forBranch($branchId)
            ->with(['member:id,full_name,membership_number,photo_path'])
            ->whereNotNull('check_in_time')
            ->orderByDesc('check_in_time')
            ->limit($limit)
            ->get()
            ->map(fn (Attendance $a) => [
                'id' => $a->id,
                'member_id' => $a->member_id,
                'member_name' => $a->member?->full_name,
                'membership_number' => $a->member?->membership_number,
                'photo_path' => $a->member?->photo_path,
                'check_in_time' => $a->check_in_time?->toIso8601String(),
                'check_out_time' => $a->check_out_time?->toIso8601String(),
                'status' => $a->status->value,
                'membership_valid' => $a->membership_valid,
            ]);
    }

    /**
     * SRS 15 - Attendance trend.
     *
     * Built from a single grouped query and then padded, rather than one query
     * per day: a 90-day chart would otherwise be 90 round trips.
     *
     * @return array<int, array{date:string, count:int}>
     */
    public function attendanceTrend(?int $branchId = null, int $days = 30): array
    {
        $from = today()->subDays($days - 1);

        $counts = Attendance::query()
            ->forBranch($branchId)
            ->whereBetween('attendance_date', [$from, today()])
            ->selectRaw('attendance_date, COUNT(*) AS total')
            ->groupBy('attendance_date')
            ->pluck('total', 'attendance_date');

        $series = [];

        for ($date = $from->copy(); $date->lessThanOrEqualTo(today()); $date->addDay()) {
            $key = $date->toDateString();

            // Days with no visits must still appear, or the chart implies the
            // gym was busy every day it has a data point for.
            $series[] = [
                'date' => $key,
                'count' => (int) ($counts[$key] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * SRS 15 - Top active members over a window.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function topMembers(?int $branchId = null, int $days = 30, int $limit = 10): Collection
    {
        return Attendance::query()
            ->forBranch($branchId)
            ->whereBetween('attendance_date', [today()->subDays($days), today()])
            ->selectRaw('member_id, COUNT(*) AS visits, MAX(check_in_time) AS last_visit')
            ->groupBy('member_id')
            ->orderByDesc('visits')
            ->limit($limit)
            ->with('member:id,full_name,membership_number')
            ->get()
            ->map(fn ($row) => [
                'member_id' => $row->member_id,
                'member_name' => $row->member?->full_name,
                'membership_number' => $row->member?->membership_number,
                'visits' => (int) $row->visits,
                'last_visit' => $row->last_visit,
            ]);
    }

    /** SRS 15 - Memberships lapsing inside the window. */
    public function expiringSoon(?int $branchId = null, ?int $days = null, int $limit = 20): Collection
    {
        $days = $days ?? (int) config('gms.membership.expiring_soon_days', 30);

        return Membership::query()
            ->forBranch($branchId)
            ->active()
            ->whereBetween('end_date', [today(), today()->addDays($days)])
            ->with('member:id,full_name,membership_number,telephone,email')
            ->orderBy('end_date')
            ->limit($limit)
            ->get()
            ->map(fn (Membership $m) => [
                'membership_id' => $m->id,
                'member_id' => $m->member_id,
                'member_name' => $m->member?->full_name,
                'membership_number' => $m->member?->membership_number,
                'telephone' => $m->member?->telephone,
                'plan_name' => $m->plan_name,
                'end_date' => $m->end_date->toDateString(),
                'days_remaining' => $m->days_remaining,
            ]);
    }

    private function revenue(?int $branchId, Carbon $from, Carbon $to): float
    {
        // Refunds are negative rows, so summing the ledger nets them off
        // automatically and "today's revenue" is genuinely what was taken.
        return round((float) Payment::query()
            ->forBranch($branchId)
            ->completed()
            ->whereBetween('paid_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->sum('amount'), 2);
    }
}

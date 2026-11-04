<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Member;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SRS 16 / 17 - Attendance and financial reporting.
 *
 * Everything here aggregates in SQL rather than in PHP. At the 10,000-member
 * scale in SRS 30, a monthly attendance report is hundreds of thousands of
 * rows, and hydrating them into models to count them is the difference
 * between a report and a timeout.
 */
class ReportService
{
    /**
     * SRS 16 - Daily attendance: one row per day in the range.
     *
     * @param  array{branch_id?:int|null, member_id?:int|null, trainer_id?:int|null, device_id?:int|null, attendance_type?:string|null}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function dailyAttendance(string $from, string $to, array $filters = []): array
    {
        $rows = $this->attendanceQuery($filters)
            ->whereBetween('attendance.attendance_date', [$from, $to])
            ->selectRaw('attendance.attendance_date AS date')
            ->selectRaw('COUNT(*) AS visits')
            ->selectRaw('COUNT(DISTINCT attendance.member_id) AS unique_members')
            ->selectRaw('SUM(CASE WHEN attendance.status = ? THEN 1 ELSE 0 END) AS still_open', ['OPEN'])
            ->selectRaw('AVG(attendance.duration_minutes) AS avg_duration')
            ->groupBy('attendance.attendance_date')
            ->orderBy('attendance.attendance_date')
            ->get()
            ->keyBy(fn ($row) => (string) $row->date);

        $series = [];
        $cursor = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();

        // Pad the gaps: a report that silently omits quiet days makes the gym
        // look busier than it was.
        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->toDateString();
            $row = $rows->get($key);

            $series[] = [
                'date' => $key,
                'visits' => (int) ($row->visits ?? 0),
                'unique_members' => (int) ($row->unique_members ?? 0),
                'still_open' => (int) ($row->still_open ?? 0),
                'avg_duration_minutes' => $row?->avg_duration === null ? null : round((float) $row->avg_duration, 1),
            ];

            $cursor->addDay();
        }

        return $series;
    }

    /**
     * SRS 16 - Monthly attendance rollup.
     *
     * @return array<int, array<string, mixed>>
     */
    public function monthlyAttendance(int $year, array $filters = []): array
    {
        $rows = $this->attendanceQuery($filters)
            ->whereYear('attendance.attendance_date', $year)
            ->selectRaw('MONTH(attendance.attendance_date) AS month')
            ->selectRaw('COUNT(*) AS visits')
            ->selectRaw('COUNT(DISTINCT attendance.member_id) AS unique_members')
            ->selectRaw('AVG(attendance.duration_minutes) AS avg_duration')
            ->groupByRaw('MONTH(attendance.attendance_date)')
            ->get()
            ->keyBy(fn ($row) => (int) $row->month);

        return collect(range(1, 12))->map(function (int $month) use ($rows, $year) {
            $row = $rows->get($month);

            return [
                'year' => $year,
                'month' => $month,
                'label' => Carbon::create($year, $month, 1)->format('M Y'),
                'visits' => (int) ($row->visits ?? 0),
                'unique_members' => (int) ($row->unique_members ?? 0),
                'avg_duration_minutes' => $row?->avg_duration === null ? null : round((float) $row->avg_duration, 1),
            ];
        })->all();
    }

    /**
     * SRS 16 - One member's attendance history with the derived statistics:
     * total visits, last visit, average visits per month, average duration.
     *
     * @return array<string, mixed>
     */
    public function memberAttendance(Member $member, ?string $from = null, ?string $to = null): array
    {
        $query = Attendance::query()->where('member_id', $member->getKey());

        if ($from && $to) {
            $query->whereBetween('attendance_date', [$from, $to]);
        }

        $visits = (clone $query)->orderByDesc('check_in_time')->limit(500)->get();

        $stats = (clone $query)
            ->selectRaw('COUNT(*) AS total_visits')
            ->selectRaw('MAX(check_in_time) AS last_visit')
            ->selectRaw('MIN(check_in_time) AS first_visit')
            ->selectRaw('AVG(duration_minutes) AS avg_duration')
            ->selectRaw('SUM(duration_minutes) AS total_minutes')
            ->first();

        $total = (int) ($stats->total_visits ?? 0);

        return [
            'member' => [
                'id' => $member->getKey(),
                'full_name' => $member->full_name,
                'membership_number' => $member->membership_number,
            ],
            'summary' => [
                'total_visits' => $total,
                'first_visit' => $stats->first_visit,
                'last_visit' => $stats->last_visit,
                'avg_duration_minutes' => $stats->avg_duration === null ? null : round((float) $stats->avg_duration, 1),
                'total_hours' => round(((int) ($stats->total_minutes ?? 0)) / 60, 1),
                'avg_visits_per_month' => $this->averageVisitsPerMonth($total, $stats->first_visit, $stats->last_visit),
            ],
            'visits' => $visits->map(fn (Attendance $a) => [
                'id' => $a->id,
                'date' => $a->attendance_date->toDateString(),
                'check_in_time' => $a->check_in_time?->toIso8601String(),
                'check_out_time' => $a->check_out_time?->toIso8601String(),
                'duration_minutes' => $a->duration_minutes,
                'attendance_type' => $a->attendance_type->value,
                'status' => $a->status->value,
                'membership_valid' => $a->membership_valid,
            ])->all(),
        ];
    }

    /**
     * SRS 16 - Visits per member across a range, with last visit date.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function memberVisitSummary(string $from, string $to, array $filters = [], int $limit = 500): Collection
    {
        return $this->attendanceQuery($filters)
            ->whereBetween('attendance.attendance_date', [$from, $to])
            ->join('members', 'members.id', '=', 'attendance.member_id')
            ->selectRaw('attendance.member_id')
            ->selectRaw('members.full_name, members.membership_number')
            ->selectRaw('COUNT(*) AS visits')
            ->selectRaw('MAX(attendance.check_in_time) AS last_visit')
            ->selectRaw('AVG(attendance.duration_minutes) AS avg_duration')
            ->groupBy('attendance.member_id', 'members.full_name', 'members.membership_number')
            ->orderByDesc('visits')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'member_id' => (int) $row->member_id,
                'full_name' => $row->full_name,
                'membership_number' => $row->membership_number,
                'visits' => (int) $row->visits,
                'last_visit' => $row->last_visit,
                'avg_duration_minutes' => $row->avg_duration === null ? null : round((float) $row->avg_duration, 1),
            ]);
    }

    /**
     * SRS 17 - Money taken in a range, broken down by method and type.
     *
     * @return array<string, mixed>
     */
    public function revenue(string $from, string $to, ?int $branchId = null): array
    {
        $base = fn () => Payment::query()->forBranch($branchId)->completed()->between($from, $to);

        $byMethod = $base()
            ->selectRaw('method, COUNT(*) AS count, SUM(amount) AS total')
            ->groupBy('method')
            ->get()
            ->map(fn ($r) => [
                'method' => $r->method instanceof \BackedEnum ? $r->method->value : $r->method,
                'count' => (int) $r->count,
                'total' => round((float) $r->total, 2),
            ]);

        $byType = $base()
            ->selectRaw('payment_type, COUNT(*) AS count, SUM(amount) AS total')
            ->groupBy('payment_type')
            ->get()
            ->map(fn ($r) => [
                'payment_type' => $r->payment_type instanceof \BackedEnum ? $r->payment_type->value : $r->payment_type,
                'count' => (int) $r->count,
                'total' => round((float) $r->total, 2),
            ]);

        $daily = $base()
            ->selectRaw('DATE(paid_at) AS date, SUM(amount) AS total, COUNT(*) AS count')
            ->groupByRaw('DATE(paid_at)')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => [
                'date' => (string) $r->date,
                'total' => round((float) $r->total, 2),
                'count' => (int) $r->count,
            ]);

        // Refunds are negative ledger rows, so gross and refunds are separated
        // here to keep "we took X and gave Y back" visible rather than netted.
        $refunds = round((float) $base()->where('amount', '<', 0)->sum('amount'), 2);
        $gross = round((float) $base()->where('amount', '>', 0)->sum('amount'), 2);

        return [
            'from' => $from,
            'to' => $to,
            'gross' => $gross,
            'refunds' => $refunds,
            'net' => round($gross + $refunds, 2),
            'by_method' => $byMethod->all(),
            'by_type' => $byType->all(),
            'daily' => $daily->all(),
        ];
    }

    /**
     * Money spent in a range, broken down the same way revenue is.
     *
     * Deliberately mirrors revenue() field for field — total, by category, by
     * method, daily — so the two can be read side by side without translating
     * between two shapes.
     *
     * @return array<string, mixed>
     */
    public function expenses(string $from, string $to, ?int $branchId = null): array
    {
        $base = fn () => Expense::query()->forBranch($branchId)->between($from, $to);

        /*
         * Aliased `total_amount`, never `total`.
         *
         * Expense has a `total` accessor (amount + tax). An aggregate aliased
         * `total` is shadowed by it, and because the accessor reads attributes
         * the aggregate query never selected, every figure came back as 0.00 —
         * a silent wrong answer, not an error.
         */
        $byCategory = $base()
            ->join('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->selectRaw('expense_categories.id, expense_categories.name, expense_categories.is_recurring')
            ->selectRaw('COUNT(*) AS entries, SUM(expenses.amount + expenses.tax_amount) AS total_amount')
            ->groupBy('expense_categories.id', 'expense_categories.name', 'expense_categories.is_recurring')
            ->orderByDesc('total_amount')
            ->get()
            ->map(fn ($r) => [
                'category_id' => (int) $r->id,
                'category' => $r->name,
                'is_recurring' => (bool) $r->is_recurring,
                'count' => (int) $r->entries,
                'total' => round((float) $r->total_amount, 2),
            ]);

        $byMethod = $base()
            ->selectRaw('method, COUNT(*) AS entries, SUM(amount + tax_amount) AS total_amount')
            ->groupBy('method')
            ->get()
            ->map(fn ($r) => [
                'method' => $r->method instanceof \BackedEnum ? $r->method->value : $r->method,
                'count' => (int) $r->entries,
                'total' => round((float) $r->total_amount, 2),
            ]);

        $daily = $base()
            ->selectRaw('expense_date AS spent_on, SUM(amount + tax_amount) AS total_amount, COUNT(*) AS entries')
            ->groupBy('expense_date')
            ->orderBy('expense_date')
            ->get()
            ->map(fn ($r) => [
                'date' => Carbon::parse($r->spent_on)->toDateString(),
                'total' => round((float) $r->total_amount, 2),
                'count' => (int) $r->entries,
            ]);

        $total = round((float) $base()->sum(DB::raw('amount + tax_amount')), 2);
        $recurring = round((float) $base()
            ->whereIn('expense_category_id', ExpenseCategory::query()->where('is_recurring', true)->select('id'))
            ->sum(DB::raw('amount + tax_amount')), 2);

        return [
            'from' => $from,
            'to' => $to,
            'total' => $total,
            // What the gym owes every month regardless of how busy it was.
            // The number that decides how many members it needs.
            'recurring' => $recurring,
            'one_off' => round($total - $recurring, 2),
            'by_category' => $byCategory->all(),
            'by_method' => $byMethod->all(),
            'daily' => $daily->all(),
        ];
    }

    /**
     * Revenue against costs: the question the system could not answer before
     * expenses existed.
     *
     * Net revenue is used, not gross — money refunded was never the gym's to
     * count, and a month with a large refund would otherwise look profitable
     * while the bank balance said otherwise.
     *
     * @return array<string, mixed>
     */
    public function profitAndLoss(string $from, string $to, ?int $branchId = null): array
    {
        $revenue = $this->revenue($from, $to, $branchId);
        $expenses = $this->expenses($from, $to, $branchId);

        $income = $revenue['net'];
        $spend = $expenses['total'];
        $profit = round($income - $spend, 2);

        return [
            'from' => $from,
            'to' => $to,
            'income' => $income,
            'gross_income' => $revenue['gross'],
            'refunds' => $revenue['refunds'],
            'expenses' => $spend,
            'profit' => $profit,
            // Undefined rather than zero when nothing came in: a month with
            // costs and no income has no meaningful margin, and showing -100%
            // or 0% both read as facts rather than as "not applicable".
            'margin_percent' => $income > 0 ? round(($profit / $income) * 100, 1) : null,
            'by_month' => $this->monthlyProfit($from, $to, $branchId),
            'expense_by_category' => $expenses['by_category'],
        ];
    }

    /**
     * Income and spend per calendar month across the range.
     *
     * Both sides are grouped in SQL and then merged over a generated list of
     * months, so a month with costs but no takings still appears. Dropping it
     * would hide exactly the months worth looking at.
     *
     * @return array<int, array<string, mixed>>
     */
    private function monthlyProfit(string $from, string $to, ?int $branchId = null): array
    {
        $income = Payment::query()
            ->forBranch($branchId)
            ->completed()
            ->between($from, $to)
            ->selectRaw("DATE_FORMAT(paid_at, '%Y-%m') AS period, SUM(amount) AS total")
            ->groupByRaw("DATE_FORMAT(paid_at, '%Y-%m')")
            ->pluck('total', 'period');

        // total_amount, not total: see the note in expenses() — the model's
        // `total` accessor shadows an aggregate of that name and silently
        // returns zero.
        $spend = Expense::query()
            ->forBranch($branchId)
            ->between($from, $to)
            ->selectRaw("DATE_FORMAT(expense_date, '%Y-%m') AS period, SUM(amount + tax_amount) AS total_amount")
            ->groupByRaw("DATE_FORMAT(expense_date, '%Y-%m')")
            ->pluck('total_amount', 'period');

        $months = [];
        $cursor = Carbon::parse($from)->startOfMonth();
        $end = Carbon::parse($to)->startOfMonth();

        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->format('Y-m');
            $earned = round((float) ($income[$key] ?? 0), 2);
            $spent = round((float) ($spend[$key] ?? 0), 2);

            $months[] = [
                'period' => $key,
                'label' => $cursor->format('M Y'),
                'income' => $earned,
                'expenses' => $spent,
                'profit' => round($earned - $spent, 2),
            ];

            $cursor->addMonth();
        }

        return $months;
    }

    /**
     * SRS 17 - Who owes money.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function outstanding(?int $branchId = null, int $limit = 500): Collection
    {
        return Invoice::query()
            ->forBranch($branchId)
            ->outstanding()
            ->with('member:id,full_name,membership_number,telephone')
            ->orderBy('due_date')
            ->limit($limit)
            ->get()
            ->map(fn (Invoice $invoice) => [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'member_id' => $invoice->member_id,
                'member_name' => $invoice->member?->full_name,
                'membership_number' => $invoice->member?->membership_number,
                'telephone' => $invoice->member?->telephone,
                'issue_date' => $invoice->issue_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'total' => round((float) $invoice->total, 2),
                'paid' => round((float) $invoice->paid_amount, 2),
                'balance' => $invoice->balance,
                'days_overdue' => $invoice->due_date && $invoice->due_date->isPast()
                    ? (int) $invoice->due_date->diffInDays(today())
                    : 0,
            ]);
    }

    /**
     * Shared attendance base query with the SRS 16 filters applied.
     *
     * @param  array<string, mixed>  $filters
     */
    private function attendanceQuery(array $filters): Builder
    {
        $query = Attendance::query();

        if (! empty($filters['branch_id'])) {
            $query->where('attendance.branch_id', $filters['branch_id']);
        }

        if (! empty($filters['member_id'])) {
            $query->where('attendance.member_id', $filters['member_id']);
        }

        if (! empty($filters['device_id'])) {
            // Either end of the visit having touched the device counts.
            $query->where(function (Builder $q) use ($filters) {
                $q->where('attendance.check_in_device_id', $filters['device_id'])
                    ->orWhere('attendance.check_out_device_id', $filters['device_id']);
            });
        }

        if (! empty($filters['attendance_type'])) {
            $query->where('attendance.attendance_type', $filters['attendance_type']);
        }

        if (! empty($filters['trainer_id'])) {
            // Trainer is a property of the member, not of the visit.
            $query->whereIn('attendance.member_id', function ($sub) use ($filters) {
                $sub->select('id')->from('members')->where('trainer_id', $filters['trainer_id']);
            });
        }

        return $query;
    }

    private function averageVisitsPerMonth(int $total, ?string $first, ?string $last): ?float
    {
        if ($total === 0 || $first === null) {
            return null;
        }

        $months = Carbon::parse($first)->diffInMonths(Carbon::parse($last ?? now()));

        // A member whose whole history is inside one month still averages a
        // month's worth, not an infinite rate.
        return round($total / max(1, $months), 1);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Services\ReportExporter;
use App\Services\ReportService;
use App\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** SRS 16 / 17 - Reports. */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly ReportExporter $exporter,
    ) {}

    /** GET /api/reports/attendance/daily */
    public function dailyAttendance(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request, 30);

        return response()->json([
            'from' => $from,
            'to' => $to,
            'data' => $this->reports->dailyAttendance($from, $to, $this->filters($request)),
        ]);
    }

    /** GET /api/reports/attendance/monthly */
    public function monthlyAttendance(Request $request): JsonResponse
    {
        $year = $request->integer('year') ?: (int) now()->year;

        return response()->json([
            'year' => $year,
            'data' => $this->reports->monthlyAttendance($year, $this->filters($request)),
        ]);
    }

    /** GET /api/reports/attendance/member/{member} - SRS 16 member statistics. */
    public function memberAttendance(Request $request, Member $member): JsonResponse
    {
        $from = $request->string('from')->toString() ?: null;
        $to = $request->string('to')->toString() ?: null;

        return response()->json($this->reports->memberAttendance($member, $from, $to));
    }

    /** GET /api/reports/attendance/members - visits per member across a range. */
    public function memberVisits(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request, 30);

        return response()->json([
            'from' => $from,
            'to' => $to,
            'data' => $this->reports->memberVisitSummary($from, $to, $this->filters($request)),
        ]);
    }

    /** GET /api/reports/revenue - SRS 17 financial summary. */
    public function revenue(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::REPORTS_FINANCIAL), 403);

        [$from, $to] = $this->range($request, 30);

        return response()->json($this->reports->revenue($from, $to, $this->branchScope($request)));
    }

    /** GET /api/reports/outstanding - SRS 17 who owes money. */
    public function outstanding(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::REPORTS_FINANCIAL), 403);

        return response()->json([
            'data' => $this->reports->outstanding($this->branchScope($request)),
        ]);
    }

    /** GET /api/reports/expenses - what the gym spent. */
    public function expenses(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::EXPENSES_VIEW), 403);

        [$from, $to] = $this->range($request, 30);

        return response()->json($this->reports->expenses($from, $to, $this->branchScope($request)));
    }

    /**
     * GET /api/reports/profit-loss - takings against costs.
     *
     * Needs both halves of the books, so it needs both permissions. An
     * accountant holds both; someone who can see revenue but not the cost base
     * is not being shown a margin.
     */
    public function profitAndLoss(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->can(Permissions::REPORTS_FINANCIAL) && $request->user()->can(Permissions::EXPENSES_VIEW),
            403,
        );

        [$from, $to] = $this->range($request, 180);

        return response()->json($this->reports->profitAndLoss($from, $to, $this->branchScope($request)));
    }

    /** GET /api/reports/exports - which reports can be exported, for this caller. */
    public function exports(Request $request): JsonResponse
    {
        $available = collect(ReportExporter::reports())
            ->filter(fn (array $definition) => $request->user()->can($definition['permission']))
            ->map(fn (array $definition, string $key) => [
                'report' => $key,
                'label' => $definition['label'],
                'columns' => $definition['columns'],
            ]);

        return response()->json(['data' => $available->values()->all()]);
    }

    /**
     * GET /api/reports/export/{report}
     *
     * One endpoint for every report rather than one per report: the column
     * headings and the permission live with the report definition, so a new
     * report is exportable the moment it is registered and cannot drift out of
     * step with the screen it came from.
     */
    public function export(Request $request, string $report): StreamedResponse
    {
        abort_unless(ReportExporter::exists($report), 404, "Unknown report: {$report}.");

        $permission = ReportExporter::reports()[$report]['permission'];
        abort_unless($request->user()->can($permission), 403);

        // Profit and loss spans months, so a 30-day default would export a
        // single row and look broken.
        [$from, $to] = $this->range($request, $report === 'profit-loss' ? 180 : 30);

        return $this->exporter->stream($report, [
            'from' => $from,
            'to' => $to,
            'branch_id' => $this->branchScope($request),
            'filters' => [
                ...$this->filters($request),
                'expense_category_id' => $request->integer('expense_category_id') ?: null,
            ],
        ]);
    }

    /**
     * GET /api/reports/attendance/export
     *
     * The original export endpoint, kept so existing links and any saved
     * bookmarks keep working. It is the generic exporter underneath.
     */
    public function exportAttendance(Request $request): StreamedResponse
    {
        return $this->export($request, 'attendance-members');
    }

    /**
     * Resolve the reporting window, defaulting to the last N days.
     *
     * @return array{0:string, 1:string}
     */
    private function range(Request $request, int $defaultDays): array
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $to = $request->filled('to') ? Carbon::parse($request->string('to')) : today();
        $from = $request->filled('from') ? Carbon::parse($request->string('from')) : $to->copy()->subDays($defaultDays - 1);

        return [$from->toDateString(), $to->toDateString()];
    }

    /** SRS 16 - the filters every attendance report shares. */
    private function filters(Request $request): array
    {
        return [
            'branch_id' => $this->branchScope($request),
            'member_id' => $request->integer('member_id') ?: null,
            'trainer_id' => $request->integer('trainer_id') ?: null,
            'device_id' => $request->integer('device_id') ?: null,
            'attendance_type' => $request->string('attendance_type')->toString() ?: null,
        ];
    }
}

<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Payment;
use App\Support\Permissions;
use Generator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export for every report, through one registry.
 *
 * Built as a registry rather than one export method per report because the
 * alternative was six endpoints that differed only in their column headings —
 * and in practice one of them would quietly stop matching its screen. A report
 * is defined once here: its permission, its headings and its rows.
 *
 * Rows are produced by generators, not arrays. A year of attendance at the SRS
 * 30 scale is hundreds of thousands of rows, and building that in memory to
 * hand to the browser is the difference between an export and a 500.
 */
class ReportExporter
{
    public function __construct(private readonly ReportService $reports) {}

    /**
     * Every exportable report.
     *
     * `permission` is the one the caller must hold. The financial reports need
     * more than REPORTS_VIEW: a receptionist may see how busy the gym was
     * without being able to download what it earned or what it costs to run.
     *
     * @return array<string, array{label:string, permission:string, columns:array<int,string>}>
     */
    public static function reports(): array
    {
        return [
            'attendance-members' => [
                'label' => 'Visits per member',
                'permission' => Permissions::REPORTS_VIEW,
                'columns' => ['Membership No', 'Member', 'Visits', 'Last Visit', 'Avg Duration (min)'],
            ],
            'attendance-daily' => [
                'label' => 'Attendance by day',
                'permission' => Permissions::REPORTS_VIEW,
                'columns' => ['Date', 'Visits', 'Unique Members', 'Still Open', 'Avg Duration (min)'],
            ],
            'revenue-payments' => [
                'label' => 'Payments',
                'permission' => Permissions::REPORTS_FINANCIAL,
                'columns' => ['Date', 'Receipt No', 'Membership No', 'Member', 'For', 'Method', 'Reference', 'Status', 'Amount'],
            ],
            'revenue-daily' => [
                'label' => 'Takings by day',
                'permission' => Permissions::REPORTS_FINANCIAL,
                'columns' => ['Date', 'Payments', 'Total'],
            ],
            'outstanding' => [
                'label' => 'Outstanding invoices',
                'permission' => Permissions::REPORTS_FINANCIAL,
                'columns' => ['Invoice No', 'Membership No', 'Member', 'Telephone', 'Issued', 'Due', 'Days Overdue', 'Total', 'Paid', 'Balance'],
            ],
            'expenses' => [
                'label' => 'Expenses',
                'permission' => Permissions::EXPENSES_VIEW,
                'columns' => ['Date', 'Reference', 'Category', 'Payee', 'Supplier Ref', 'Method', 'Amount', 'Tax', 'Total', 'Description'],
            ],
            'expenses-by-category' => [
                'label' => 'Expenses by category',
                'permission' => Permissions::EXPENSES_VIEW,
                'columns' => ['Category', 'Recurring', 'Entries', 'Total'],
            ],
            'profit-loss' => [
                'label' => 'Profit and loss',
                'permission' => Permissions::REPORTS_FINANCIAL,
                'columns' => ['Month', 'Income', 'Expenses', 'Profit'],
            ],
        ];
    }

    public static function exists(string $report): bool
    {
        return array_key_exists($report, self::reports());
    }

    /**
     * @param  array{from:string, to:string, branch_id?:int|null, filters?:array<string,mixed>}  $options
     */
    public function stream(string $report, array $options): StreamedResponse
    {
        $definition = self::reports()[$report] ?? null;

        if ($definition === null) {
            throw new \InvalidArgumentException("Unknown report: {$report}.");
        }

        $filename = sprintf('%s-%s-to-%s.csv', $report, $options['from'], $options['to']);
        $rows = $this->rows($report, $options);

        return response()->streamDownload(function () use ($definition, $rows) {
            $handle = fopen('php://output', 'w');

            /*
             * A byte-order mark, because the audience for these files is Excel
             * on Windows. Without it Excel reads UTF-8 as the local codepage
             * and a member called "Nuwan Wickramasinghe" is fine but "Chamódi"
             * is not. It costs three bytes and every other tool ignores it.
             */
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, $definition['columns']);

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            // Otherwise a proxy can hand back yesterday's figures for a URL
            // whose date range happens to match.
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return Generator<int, array<int, mixed>>
     */
    private function rows(string $report, array $options): Generator
    {
        $from = $options['from'];
        $to = $options['to'];
        $branchId = $options['branch_id'] ?? null;
        $filters = $options['filters'] ?? [];

        switch ($report) {
            case 'attendance-members':
                foreach ($this->reports->memberVisitSummary($from, $to, $filters, 100000) as $row) {
                    yield [
                        $row['membership_number'],
                        $row['full_name'],
                        $row['visits'],
                        $row['last_visit'],
                        $row['avg_duration_minutes'],
                    ];
                }

                return;

            case 'attendance-daily':
                foreach ($this->reports->dailyAttendance($from, $to, $filters) as $row) {
                    yield [
                        $row['date'],
                        $row['visits'],
                        $row['unique_members'],
                        $row['still_open'],
                        $row['avg_duration_minutes'],
                    ];
                }

                return;

            case 'revenue-payments':
                // cursor() rather than get(): this is the one export that can
                // reach six figures of rows on a busy year.
                $query = Payment::query()
                    ->with('member:id,full_name,membership_number')
                    ->forBranch($branchId)
                    ->completed()
                    ->between($from, $to)
                    ->orderBy('paid_at');

                foreach ($query->cursor() as $payment) {
                    yield [
                        $payment->paid_at?->toDateTimeString(),
                        $payment->receipt_number,
                        $payment->member?->membership_number,
                        $payment->member?->full_name,
                        $payment->payment_type?->label(),
                        $payment->method?->label(),
                        $payment->reference,
                        $payment->status,
                        (float) $payment->amount,
                    ];
                }

                return;

            case 'revenue-daily':
                foreach ($this->reports->revenue($from, $to, $branchId)['daily'] as $row) {
                    yield [$row['date'], $row['count'], $row['total']];
                }

                return;

            case 'outstanding':
                foreach ($this->reports->outstanding($branchId, 100000) as $row) {
                    yield [
                        $row['invoice_number'],
                        $row['membership_number'],
                        $row['member_name'],
                        $row['telephone'],
                        $row['issue_date'],
                        $row['due_date'],
                        $row['days_overdue'],
                        $row['total'],
                        $row['paid'],
                        $row['balance'],
                    ];
                }

                return;

            case 'expenses':
                $query = Expense::query()
                    ->with('category:id,name')
                    ->forBranch($branchId)
                    ->between($from, $to)
                    ->when(
                        ! empty($filters['expense_category_id']),
                        fn ($q) => $q->where('expense_category_id', $filters['expense_category_id']),
                    )
                    ->orderBy('expense_date');

                foreach ($query->cursor() as $expense) {
                    yield [
                        $expense->expense_date?->toDateString(),
                        $expense->reference,
                        $expense->category?->name,
                        $expense->payee,
                        $expense->supplier_reference,
                        $expense->method?->label(),
                        (float) $expense->amount,
                        (float) $expense->tax_amount,
                        $expense->total,
                        $expense->description,
                    ];
                }

                return;

            case 'expenses-by-category':
                foreach ($this->reports->expenses($from, $to, $branchId)['by_category'] as $row) {
                    yield [
                        $row['category'],
                        $row['is_recurring'] ? 'Yes' : 'No',
                        $row['count'],
                        $row['total'],
                    ];
                }

                return;

            case 'profit-loss':
                $summary = $this->reports->profitAndLoss($from, $to, $branchId);

                foreach ($summary['by_month'] as $row) {
                    yield [$row['label'], $row['income'], $row['expenses'], $row['profit']];
                }

                // A total row, because a profit report read without one invites
                // the reader to add the column up themselves and get it wrong.
                yield ['Total', $summary['income'], $summary['expenses'], $summary['profit']];

                return;
        }
    }
}

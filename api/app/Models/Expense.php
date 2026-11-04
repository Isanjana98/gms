<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToBranch;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One cost the gym incurred.
 *
 * Unlike a payment this is editable: an expense is a record of something that
 * happened outside the system, and a mistyped supplier invoice is corrected,
 * not reversed with a negative row. The audit log keeps the before and after,
 * so the correction is still accountable.
 */
#[Fillable([
    'branch_id', 'expense_category_id', 'reference', 'expense_date',
    'amount', 'tax_amount', 'method', 'payee', 'supplier_reference',
    'description', 'recorded_by',
])]
class Expense extends Model
{
    use Auditable, BelongsToBranch, SoftDeletes;

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'method' => PaymentMethod::class,
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Inclusive on both ends.
     *
     * expense_date is a date, not a timestamp, so unlike the payment ledger
     * this needs no start/end-of-day handling — and must not have any, or a
     * spend dated on the closing day falls outside its own month.
     */
    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('expenses.expense_date', [
            Carbon::parse($from)->toDateString(),
            Carbon::parse($to)->toDateString(),
        ]);
    }

    /**
     * What the expense actually cost, tax included.
     *
     * Beware when aggregating: this accessor shadows any select alias called
     * `total`, and because it recomputes from `amount` and `tax_amount` it
     * returns 0.00 for a query that selected neither — a wrong answer with no
     * error attached. Alias sums `total_amount` instead. This has caught us
     * twice; ExpenseTest::the_expense_report_totals_are_not_zero guards it.
     */
    public function getTotalAttribute(): float
    {
        return round((float) $this->amount + (float) $this->tax_amount, 2);
    }
}

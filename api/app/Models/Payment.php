<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToBranch;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SRS 17 - One entry in the money ledger.
 *
 * Refunds are negative rows referencing the original payment, never edits to
 * it, so the sum of a member's rows is always their true position and the
 * history of what was collected stays readable.
 */
#[Fillable([
    'branch_id', 'member_id', 'invoice_id', 'membership_id', 'refund_of_payment_id',
    'receipt_number', 'payment_type', 'amount', 'discount', 'method', 'reference',
    'paid_at', 'status', 'notes', 'received_by',
])]
class Payment extends Model
{
    use Auditable, BelongsToBranch, SoftDeletes;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'discount' => 'decimal:2',
            'paid_at' => 'datetime',
            'payment_type' => PaymentType::class,
            'method' => PaymentMethod::class,
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function refundOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'refund_of_payment_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * Rows where money actually moved, and so the ones that sum to a true
     * balance.
     *
     * REFUNDED is included deliberately. That status marks an original
     * payment whose money has since been returned - but the return is its own
     * negative row. Excluding the original while keeping its reversal makes a
     * fully refunded membership sum to the negative of what was paid, so the
     * member appears to be owed money they already have back.
     *
     * PENDING and FAILED are excluded: nothing moved.
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereIn('status', ['COMPLETED', 'REFUNDED']);
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('paid_at', [
            Carbon::parse($from)->startOfDay(),
            Carbon::parse($to)->endOfDay(),
        ]);
    }

    public function getIsRefundAttribute(): bool
    {
        return $this->payment_type === PaymentType::REFUND || (float) $this->amount < 0;
    }
}

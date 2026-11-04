<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** SRS 18 - A billing document issued to a member. */
#[Fillable([
    'branch_id', 'member_id', 'membership_id', 'invoice_number', 'issue_date',
    'due_date', 'sub_total', 'discount', 'tax', 'total', 'status', 'notes', 'created_by',
])]
class Invoice extends Model
{
    use Auditable, BelongsToBranch, SoftDeletes;

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'sub_total' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** SRS 17 - Outstanding payments. */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', ['UNPAID', 'PARTIAL'])
            ->whereColumn('paid_amount', '<', 'total');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->outstanding()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', today());
    }

    public function getBalanceAttribute(): float
    {
        return round((float) $this->total - (float) $this->paid_amount, 2);
    }
}

<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Enums\PaymentStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToBranch;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** SRS 5 / 6 - One purchased term of a membership plan. */
#[Fillable([
    'branch_id', 'member_id', 'membership_plan_id', 'renewed_from_id', 'reference',
    'plan_name', 'start_date', 'end_date', 'grace_days', 'price', 'discount',
    'registration_fee', 'final_amount', 'paid_amount', 'payment_status', 'status',
    'activated_at', 'notes', 'created_by',
])]
class Membership extends Model
{
    use Auditable, BelongsToBranch, SoftDeletes;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'activated_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'price' => 'decimal:2',
            'discount' => 'decimal:2',
            'registration_fee' => 'decimal:2',
            'final_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'grace_days' => 'integer',
            'status' => MembershipStatus::class,
            'payment_status' => PaymentStatus::class,
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    /** The term this one renewed, if any - walks the member's history back. */
    public function renewedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renewed_from_id');
    }

    /** The term that renewed this one, if it has already been renewed. */
    public function renewal(): HasOne
    {
        return $this->hasOne(self::class, 'renewed_from_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', MembershipStatus::ACTIVE);
    }

    /** Terms whose paid-through date (including grace) has passed. */
    public function scopeLapsed(Builder $query): Builder
    {
        return $query->whereIn('status', [MembershipStatus::ACTIVE, MembershipStatus::PENDING])
            ->whereRaw('DATE_ADD(end_date, INTERVAL grace_days DAY) < ?', [today()->toDateString()]);
    }

    public function scopeExpiringOn(Builder $query, string $date): Builder
    {
        return $query->where('status', MembershipStatus::ACTIVE)->whereDate('end_date', $date);
    }

    /** Last day of access, grace period included. */
    public function getAccessUntilAttribute(): CarbonInterface
    {
        return $this->end_date->copy()->addDays($this->grace_days);
    }

    public function getBalanceAttribute(): float
    {
        return round((float) $this->final_amount - (float) $this->paid_amount, 2);
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->access_until->isBefore(today());
    }

    public function getDaysRemainingAttribute(): int
    {
        return (int) today()->diffInDays($this->end_date, false);
    }

    /**
     * Whether this term admits the member today (SRS 10). Separate from the
     * member-level check: a member can be blacklisted while holding a term
     * that is technically still live.
     */
    public function allowsEntryOn(?CarbonInterface $date = null): bool
    {
        $date = $date ?? today();

        return $this->status->allowsEntry()
            && $this->start_date->startOfDay()->lessThanOrEqualTo($date)
            && $this->access_until->endOfDay()->greaterThanOrEqualTo($date);
    }
}

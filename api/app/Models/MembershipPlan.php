<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToBranch;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** SRS 5 - A sellable membership package. */
#[Fillable([
    'branch_id', 'code', 'name', 'description', 'duration_value', 'duration_unit',
    'price', 'default_discount', 'registration_fee', 'grace_days', 'is_active', 'sort_order',
])]
class MembershipPlan extends Model
{
    use Auditable, BelongsToBranch, SoftDeletes;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'default_discount' => 'decimal:2',
            'registration_fee' => 'decimal:2',
            'duration_value' => 'integer',
            'grace_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The last day covered by a term starting on $start.
     *
     * A one-month membership bought on the 1st runs to the 30th/31st, not to
     * the 1st of next month - the member would otherwise get a free day on
     * every renewal, which compounds over a year of monthly terms.
     *
     * Month arithmetic uses addMonthsNoOverflow so 31 Jan + 1 month lands on
     * 28 Feb rather than spilling into March.
     */
    public function calculateEndDate(CarbonInterface $start): CarbonInterface
    {
        $end = match ($this->duration_unit) {
            'DAY' => $start->copy()->addDays($this->duration_value),
            'WEEK' => $start->copy()->addWeeks($this->duration_value),
            'YEAR' => $start->copy()->addYearsNoOverflow($this->duration_value),
            default => $start->copy()->addMonthsNoOverflow($this->duration_value),
        };

        return $end->subDay()->startOfDay();
    }

    public function getDurationLabelAttribute(): string
    {
        $unit = strtolower($this->duration_unit);

        return $this->duration_value.' '.$unit.($this->duration_value > 1 ? 's' : '');
    }
}

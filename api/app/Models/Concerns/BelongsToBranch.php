<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SRS 22 - Branch scoping.
 *
 * Applied as an explicit scope rather than a global one: a Super Administrator
 * legitimately needs to see every branch, and a global scope that must be
 * disabled for the most privileged role is a trap waiting for the first
 * developer who forgets it exists. Callers ask for the restriction by name.
 */
trait BelongsToBranch
{
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Limit to one branch. A null id is a no-op, meaning "all branches". */
    public function scopeForBranch(Builder $query, int|Branch|null $branch): Builder
    {
        if ($branch === null) {
            return $query;
        }

        return $query->where(
            $this->qualifyColumn('branch_id'),
            $branch instanceof Branch ? $branch->getKey() : $branch,
        );
    }

    /**
     * Limit to the branches a user may see. Users without a branch assignment
     * (super admins) are unrestricted.
     */
    public function scopeVisibleTo(Builder $query, ?object $user): Builder
    {
        if ($user === null || $user->branch_id === null) {
            return $query;
        }

        if (method_exists($user, 'can') && $user->can('branches.view-all')) {
            return $query;
        }

        return $query->where($this->qualifyColumn('branch_id'), $user->branch_id);
    }
}

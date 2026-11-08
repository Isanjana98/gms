<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One of the gym's own questions on the joining form. */
#[Fillable([
    'branch_id', 'key', 'label', 'help', 'type', 'options',
    'is_required', 'sort_order', 'is_active',
])]
class RegistrationQuestion extends Model
{
    use Auditable, SoftDeletes;

    public const TYPES = ['BOOLEAN', 'TEXT', 'TEXTAREA', 'CHOICE'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The questions one branch's form should show, in order.
     *
     * Branch-specific questions and gym-wide ones together: a question with
     * no branch is asked everywhere, which is what a single-site gym gets
     * without ever thinking about branches.
     */
    public function scopeForBranch(Builder $query, ?int $branchId): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(function (Builder $q) use ($branchId) {
                $q->whereNull('branch_id');

                if ($branchId !== null) {
                    $q->orWhere('branch_id', $branchId);
                }
            })
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}

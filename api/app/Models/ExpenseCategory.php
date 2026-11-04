<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** The gym's own chart of accounts for spending. */
#[Fillable(['code', 'name', 'description', 'is_recurring', 'is_active', 'sort_order'])]
class ExpenseCategory extends Model
{
    use Auditable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_recurring' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}

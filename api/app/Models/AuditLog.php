<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * SRS 25 - An immutable record of something that happened.
 *
 * Append-only by design: no updated_at, no soft deletes, no relationship back
 * to users. Once written, a row is evidence and nothing in the application
 * offers a way to change it.
 */
#[Fillable([
    'user_id', 'user_name', 'user_role', 'branch_id', 'event', 'auditable_type',
    'auditable_id', 'description', 'old_values', 'new_values', 'ip_address',
    'user_agent', 'url', 'method', 'created_at',
])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function scopeForRecord(Builder $query, string $type, int $id): Builder
    {
        return $query->where('auditable_type', $type)->where('auditable_id', $id);
    }

    public function scopeEvent(Builder $query, string $event): Builder
    {
        return $query->where('event', $event);
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('created_at', [
            Carbon::parse($from)->startOfDay(),
            Carbon::parse($to)->endOfDay(),
        ]);
    }

    /** "Member" rather than "App\Models\Member" for display. */
    public function getRecordTypeAttribute(): ?string
    {
        return $this->auditable_type ? class_basename($this->auditable_type) : null;
    }
}

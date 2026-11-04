<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** SRS 13 - The record of one synchronisation run. */
#[Fillable([
    'device_id', 'trigger', 'type', 'status', 'window_from', 'window_to',
    'records_fetched', 'records_new', 'records_duplicate', 'records_unmatched',
    'records_rejected', 'records_failed', 'started_at', 'finished_at',
    'duration_ms', 'message', 'context', 'triggered_by',
])]
class AttendanceSyncLog extends Model
{
    protected function casts(): array
    {
        return [
            'window_from' => 'datetime',
            'window_to' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'context' => 'array',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'device_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->whereIn('status', ['FAILED', 'PARTIAL']);
    }

    /** Close the run out, stamping how long it took. */
    public function finish(string $status, ?string $message = null): void
    {
        $finishedAt = now();

        $this->forceFill([
            'status' => $status,
            'message' => $message,
            'finished_at' => $finishedAt,
            'duration_ms' => (int) $this->started_at->diffInMilliseconds($finishedAt),
        ])->save();
    }
}

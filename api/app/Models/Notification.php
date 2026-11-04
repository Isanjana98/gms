<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** SRS 21 - A queued outbound message. */
#[Fillable([
    'branch_id', 'member_id', 'user_id', 'type', 'channel', 'recipient',
    'subject', 'body', 'data', 'status', 'scheduled_for', 'dedupe_key',
])]
class Notification extends Model
{
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Queued messages whose send time has arrived. */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', 'PENDING')
            ->where(function (Builder $q) {
                $q->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', now());
            });
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function markSent(): void
    {
        $this->forceFill([
            'status' => 'SENT',
            'sent_at' => now(),
            'attempts' => $this->attempts + 1,
            'error' => null,
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => 'FAILED',
            'attempts' => $this->attempts + 1,
            'error' => substr($error, 0, 255),
        ])->save();
    }
}

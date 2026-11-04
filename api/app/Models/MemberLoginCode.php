<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time sign-in code for the member app.
 *
 * Deliberately not Auditable: an audit row per sign-in attempt would bury the
 * log that exists to show what staff did to member records.
 */
#[Fillable([
    'member_id', 'code_hash', 'channel', 'destination_hint',
    'expires_at', 'attempts', 'consumed_at', 'ip_address',
])]
class MemberLoginCode extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** Still usable: not yet spent, and not yet expired. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')->where('expires_at', '>', now());
    }
}

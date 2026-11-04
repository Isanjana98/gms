<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SRS 8 / 24 - The link between a member and their identity on one device.
 *
 * Holds no fingerprint data of any kind: enrolment happens on the reader, and
 * the template never leaves it (SRS 26).
 */
#[Fillable([
    'member_id', 'device_id', 'biometric_user_id', 'device_user_name',
    'enrollment_status', 'fingerprint_count', 'has_card', 'has_face',
    'sync_status', 'is_active', 'verified_at', 'last_synced_at',
])]
class BiometricUser extends Model
{
    use Auditable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'fingerprint_count' => 'integer',
            'has_card' => 'boolean',
            'has_face' => 'boolean',
            'is_active' => 'boolean',
            'verified_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'device_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** SRS 24 - Device users that resolve to no member. */
    public function scopeUnmatched(Builder $query): Builder
    {
        return $query->whereNull('member_id');
    }

    /** Enrolled and confirmed present on the device. */
    public function scopeEnrolled(Builder $query): Builder
    {
        return $query->where('enrollment_status', 'ENROLLED');
    }

    public function getIsVerifiedAttribute(): bool
    {
        return $this->verified_at !== null && $this->fingerprint_count > 0;
    }
}

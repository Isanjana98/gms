<?php

namespace App\Models;

use App\Enums\MemberStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Sanctum\HasApiTokens;

/**
 * SRS 4 - Member master record.
 *
 * Authenticatable as well, so a member can hold a Sanctum token and sign in
 * to the mobile app. Only the bare Authenticatable trait is used, never
 * Laravel's Foundation user base class: that one also brings Authorizable,
 * and a member must never be something you can ask `->can('members.view')`
 * about and get a considered answer. A member is not a small staff account.
 *
 * Members have no password at all. See MemberAuthService.
 */
#[Fillable([
    'branch_id', 'trainer_id', 'membership_number', 'full_name', 'nic_passport',
    'date_of_birth', 'gender', 'address', 'city', 'telephone', 'email',
    'emergency_contact_name', 'emergency_contact_number', 'join_date',
    'photo_path', 'status', 'notes', 'created_by',
])]
class Member extends Model implements AuthenticatableContract
{
    use Auditable, AuthenticatableTrait, BelongsToBranch, HasApiTokens, SoftDeletes;

    /**
     * The database has these defaults too, but a database default only exists
     * after the row is read back. A Member::create() without an explicit
     * status returns an instance whose `status` is null, and the attendance
     * path calls methods on it - so the default belongs here as well.
     */
    protected $attributes = [
        'status' => MemberStatus::ACTIVE->value,
        'total_visits' => 0,
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'join_date' => 'date',
            'membership_expires_on' => 'date',
            'last_visit_at' => 'datetime',
            'status' => MemberStatus::class,
        ];
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class)->latest('start_date');
    }

    /** The term the member is on right now, kept current by MembershipService. */
    public function currentMembership(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'current_membership_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function biometricUsers(): HasMany
    {
        return $this->hasMany(BiometricUser::class);
    }

    public function workoutPlans(): HasMany
    {
        return $this->hasMany(WorkoutPlan::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(MemberDocument::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', MemberStatus::ACTIVE);
    }

    /**
     * Free-text search across the fields reception actually types: name, phone,
     * membership number and NIC.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('full_name', 'like', $like)
                ->orWhere('membership_number', 'like', $like)
                ->orWhere('telephone', 'like', $like)
                ->orWhere('nic_passport', 'like', $like)
                ->orWhere('email', 'like', $like);
        });
    }

    /** Memberships lapsing within the window - drives the SRS 15 dashboard card. */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull('membership_expires_on')
            ->whereBetween('membership_expires_on', [today(), today()->addDays($days)]);
    }

    public function getAgeAttribute(): ?int
    {
        return $this->date_of_birth?->age;
    }
}

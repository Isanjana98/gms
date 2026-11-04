<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Enums\AttendanceType;
use App\Enums\VerificationType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** SRS 12 - One gym visit. */
#[Fillable([
    'branch_id', 'member_id', 'membership_id', 'check_in_device_id', 'check_out_device_id',
    'biometric_user_id', 'attendance_date', 'check_in_time', 'check_out_time',
    'attendance_type', 'verification_type', 'status', 'membership_valid',
    'validation_message', 'notes', 'created_by', 'updated_by',
])]
class Attendance extends Model
{
    use Auditable, BelongsToBranch, SoftDeletes;

    protected $table = 'attendance';

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'check_in_time' => 'datetime',
            'check_out_time' => 'datetime',
            'duration_minutes' => 'integer',
            'membership_valid' => 'boolean',
            'attendance_type' => AttendanceType::class,
            'verification_type' => VerificationType::class,
            'status' => AttendanceStatus::class,
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function checkInDevice(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'check_in_device_id');
    }

    public function checkOutDevice(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'check_out_device_id');
    }

    public function eventLogs(): HasMany
    {
        return $this->hasMany(BiometricEventLog::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', AttendanceStatus::OPEN);
    }

    public function scopeOnDate(Builder $query, string $date): Builder
    {
        return $query->whereDate('attendance_date', $date);
    }

    public function scopeBetweenDates(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('attendance_date', [$from, $to]);
    }

    /** Recompute the stored duration from the two timestamps. */
    public function recalculateDuration(): void
    {
        $this->duration_minutes = $this->check_in_time && $this->check_out_time
            ? max(0, (int) $this->check_in_time->diffInMinutes($this->check_out_time))
            : null;
    }
}

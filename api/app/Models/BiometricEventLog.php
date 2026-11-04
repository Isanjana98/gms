<?php

namespace App\Models;

use App\Enums\AttendanceDirection;
use App\Enums\EventProcessStatus;
use App\Enums\VerificationType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A raw scan as the device reported it, before interpretation. */
#[Fillable([
    'device_id', 'member_id', 'attendance_id', 'biometric_user_id', 'event_time',
    'event_hash', 'verification_type', 'direction', 'process_status',
    'process_message', 'source', 'raw_payload',
])]
class BiometricEventLog extends Model
{
    protected function casts(): array
    {
        return [
            'event_time' => 'datetime',
            'raw_payload' => 'array',
            'verification_type' => VerificationType::class,
            'direction' => AttendanceDirection::class,
            'process_status' => EventProcessStatus::class,
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'device_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    /**
     * Stable identity for one physical scan.
     *
     * Everything that makes a scan unique goes in, and nothing that varies
     * between reads of the same scan does: a device re-sending the same event
     * after a reconnect must hash identically, or the unique index cannot stop
     * the duplicate. Times are normalised to UTC because Hikvision reports
     * local time with an offset and the same instant can arrive written two
     * ways.
     */
    public static function makeHash(
        int $deviceId,
        ?string $biometricUserId,
        \DateTimeInterface $eventTime,
        string $direction,
    ): string {
        return hash('sha256', implode('|', [
            $deviceId,
            $biometricUserId ?? '-',
            Carbon::instance($eventTime)->utc()->format('Y-m-d H:i:s'),
            $direction,
        ]));
    }

    public function scopeUnmatched(Builder $query): Builder
    {
        return $query->where('process_status', EventProcessStatus::UNMATCHED);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('process_status', EventProcessStatus::PENDING);
    }
}

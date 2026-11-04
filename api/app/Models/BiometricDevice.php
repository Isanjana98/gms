<?php

namespace App\Models;

use App\Enums\DeviceMode;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToBranch;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** SRS 23 - A fingerprint reader. */
#[Fillable([
    'branch_id', 'code', 'name', 'driver', 'model', 'serial_number', 'ip_address',
    'port', 'use_https', 'username', 'password', 'mode', 'sync_mode', 'is_active', 'notes',
])]
#[Hidden(['password'])]
class BiometricDevice extends Model
{
    use Auditable, BelongsToBranch, SoftDeletes;

    protected function casts(): array
    {
        return [
            // The device password opens a physical door. Encrypting it means a
            // leaked database dump is not also a set of keys to the building.
            'password' => 'encrypted',
            'port' => 'integer',
            'use_https' => 'boolean',
            'is_active' => 'boolean',
            'mode' => DeviceMode::class,
            'last_seen_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'last_event_at' => 'datetime',
        ];
    }

    /** Never audit-log the device password, even encrypted. */
    protected function auditExclude(): array
    {
        return [...$this->defaultAuditExclude(), 'password'];
    }

    public function biometricUsers(): HasMany
    {
        return $this->hasMany(BiometricUser::class, 'device_id');
    }

    public function eventLogs(): HasMany
    {
        return $this->hasMany(BiometricEventLog::class, 'device_id');
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(AttendanceSyncLog::class, 'device_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Devices the scheduler should poll. */
    public function scopePollable(Builder $query): Builder
    {
        return $query->active()->whereIn('sync_mode', ['PULL', 'BOTH']);
    }

    public function getBaseUrlAttribute(): string
    {
        $scheme = $this->use_https ? 'https' : 'http';
        $port = $this->port;

        // Omit the port when it is the scheme default, because some firmware
        // rejects an explicit :80 in the Host header.
        $isDefault = ($this->use_https && $port === 443) || (! $this->use_https && $port === 80);

        return $isDefault
            ? "{$scheme}://{$this->ip_address}"
            : "{$scheme}://{$this->ip_address}:{$port}";
    }

    /**
     * How far back to pull when the device has never synced. Without a floor
     * the first sync would ask for every event since the epoch, which large
     * readers answer slowly or not at all.
     */
    public function defaultSyncFloor(): CarbonInterface
    {
        return now()->subDays(7);
    }

    public function markOnline(): void
    {
        $this->forceFill([
            'connection_status' => 'ONLINE',
            'last_seen_at' => now(),
            'last_error' => null,
            'consecutive_failures' => 0,
        ])->save();
    }

    public function markFailure(string $error, string $status = 'OFFLINE'): void
    {
        $this->forceFill([
            'connection_status' => $status,
            'last_error' => substr($error, 0, 255),
            'consecutive_failures' => $this->consecutive_failures + 1,
        ])->save();
    }
}

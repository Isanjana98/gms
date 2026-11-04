<?php

namespace App\Support;

use App\Models\Setting;

/**
 * One place to ask "what is the rule here?".
 *
 * Resolution order is branch override -> global database setting -> config
 * default. That ordering matters: a chain can set a house rule once, a single
 * branch can differ, and a fresh install still works before anyone has opened
 * the settings screen.
 */
class GymSettings
{
    /** Settings-table key => config/gms.php path. */
    private const CONFIG_MAP = [
        'attendance.reject_invalid_membership' => 'gms.attendance.reject_invalid_membership',
        'attendance.duplicate_window_minutes' => 'gms.attendance.duplicate_window_minutes',
        'attendance.min_session_minutes' => 'gms.attendance.min_session_minutes',
        'attendance.auto_close_hour' => 'gms.attendance.auto_close_hour',
        'attendance.max_session_hours' => 'gms.attendance.max_session_hours',
        'membership.renew_from_expiry' => 'gms.membership.renew_from_expiry',
        'membership.renew_chain_grace_days' => 'gms.membership.renew_chain_grace_days',
        'membership.expiring_soon_days' => 'gms.membership.expiring_soon_days',
    ];

    public function get(string $key, ?int $branchId = null, mixed $default = null): mixed
    {
        $fallback = $default;

        if (isset(self::CONFIG_MAP[$key])) {
            $fallback = config(self::CONFIG_MAP[$key], $default);
        }

        return Setting::get($key, $fallback, $branchId);
    }

    public function bool(string $key, ?int $branchId = null, bool $default = false): bool
    {
        return filter_var($this->get($key, $branchId, $default), FILTER_VALIDATE_BOOLEAN);
    }

    public function int(string $key, ?int $branchId = null, int $default = 0): int
    {
        return (int) $this->get($key, $branchId, $default);
    }

    public function float(string $key, ?int $branchId = null, float $default = 0.0): float
    {
        return (float) $this->get($key, $branchId, $default);
    }

    public function set(string $key, mixed $value, ?int $branchId = null, string $type = 'string'): void
    {
        Setting::set($key, $value, $branchId, $type);
    }

    /** Whether an invalid membership blocks entry at this branch (SRS 10). */
    public function rejectsInvalidMembership(?int $branchId = null): bool
    {
        return $this->bool('attendance.reject_invalid_membership', $branchId, true);
    }
}

<?php

namespace App\Console\Commands;

use App\Models\BiometricDevice;
use App\Services\Biometric\BiometricSyncService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * SRS 13 - The biometric integration agent.
 *
 * Runs as a scheduled process rather than inside a web request. The SRS asks
 * for the web application not to talk to devices directly, and this is what
 * satisfies that: it is a separate process that can live on the gym LAN with
 * the readers, while the web tier need not reach them at all.
 */
class SyncBiometricAttendance extends Command
{
    protected $signature = 'biometric:sync
        {--device= : Sync only this device id or code}
        {--users : Also reconcile the device user list (SRS 24)}';

    protected $description = 'Pull attendance from biometric devices into the database (SRS 13)';

    public function handle(BiometricSyncService $sync): int
    {
        $devices = $this->devices();

        if ($devices->isEmpty()) {
            $this->components->warn('No active devices are configured for attendance pulling.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($devices as $device) {
            $log = $sync->syncAttendance($device, $this->option('device') ? 'MANUAL' : 'SCHEDULED');

            $line = sprintf(
                '%s: %s (%d fetched, %d new, %d duplicate, %d unmatched, %d rejected)',
                $device->name,
                $log->status,
                $log->records_fetched,
                $log->records_new,
                $log->records_duplicate,
                $log->records_unmatched,
                $log->records_rejected,
            );

            if ($log->status === 'FAILED') {
                $failures++;
                $this->components->error($line.' - '.$log->message);
            } elseif ($log->records_new > 0 || $this->option('device')) {
                $this->components->info($line);
            }

            if ($this->option('users')) {
                $userLog = $sync->syncUsersDown($device);
                $this->components->info("{$device->name} users: {$userLog->message}");
            }
        }

        // A partial failure is still a failure to the scheduler: one device
        // down out of three should be visible in monitoring.
        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return Collection<int, BiometricDevice> */
    private function devices()
    {
        $selector = $this->option('device');

        if ($selector === null) {
            return BiometricDevice::query()->pollable()->get();
        }

        return BiometricDevice::query()
            ->where(fn ($q) => $q->where('id', $selector)->orWhere('code', $selector))
            ->get();
    }
}

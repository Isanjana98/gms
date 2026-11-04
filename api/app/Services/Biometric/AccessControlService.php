<?php

namespace App\Services\Biometric;

use App\Models\BiometricUser;
use App\Models\Member;
use App\Services\Biometric\DTO\DeviceUser;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the reader's idea of who may come in matched to ours.
 *
 * A membership lapsing is a fact in the database; until somebody tells the
 * terminal, the finger still opens the door. The scan is checked on the way
 * in and a lapsed member is refused and recorded either way - but a door that
 * physically opens for somebody who has not paid is not a refusal anybody
 * believes in, and the member certainly does not.
 *
 * **Disabled, never deleted.** Hikvision keeps fingerprint templates against
 * the user record, so removing one to lock somebody out would mean enrolling
 * them again when they renew - a trip to the gym and a queue at the desk for
 * something that should take effect the moment the payment lands. Clearing
 * `Valid.enable` leaves the finger where it is and simply stops it working.
 *
 * Runs nightly for expiries nobody touched, and directly after a renewal so
 * that a member who has just paid is not told to come back tomorrow.
 */
class AccessControlService
{
    public function __construct(
        private readonly DeviceDriverFactory $drivers,
        private readonly EnrollmentService $enrollment,
    ) {}

    /**
     * Bring one member's access into line on every reader they are on.
     *
     * @return int how many readers were actually changed
     */
    public function syncMember(Member $member): int
    {
        $shouldAdmit = $this->enrollment->shouldAdmit($member);
        $changed = 0;

        $mappings = BiometricUser::query()
            ->with('device')
            ->where('member_id', $member->getKey())
            ->where('is_active', true)
            ->get();

        foreach ($mappings as $mapping) {
            if ($this->apply($mapping, $member, $shouldAdmit)) {
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Sweep every enrolled member.
     *
     * @return array{checked: int, changed: int, failed: int}
     */
    public function syncAll(): array
    {
        $checked = 0;
        $changed = 0;
        $failed = 0;

        BiometricUser::query()
            ->with(['device', 'member.currentMembership'])
            ->whereNotNull('member_id')
            ->where('is_active', true)
            // Chunked by id: this walks every enrolled member on every reader,
            // and a gym with a few thousand should not load them all at once.
            ->chunkById(200, function ($mappings) use (&$checked, &$changed, &$failed) {
                foreach ($mappings as $mapping) {
                    $member = $mapping->member;

                    if ($member === null) {
                        continue;
                    }

                    $checked++;

                    try {
                        if ($this->apply($mapping, $member, $this->enrollment->shouldAdmit($member))) {
                            $changed++;
                        }
                    } catch (\Throwable $e) {
                        // One unreachable reader must not stop the sweep: the
                        // others still need bringing up to date, and this one
                        // is retried on the next run.
                        $failed++;

                        Log::warning('Could not sync device access.', [
                            'device' => $mapping->device?->code,
                            'biometric_user_id' => $mapping->biometric_user_id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        return ['checked' => $checked, 'changed' => $changed, 'failed' => $failed];
    }

    /**
     * Push the decision to one reader, if it differs from what we last sent.
     *
     * Nothing is sent when the state already matches. A nightly sweep across
     * every member would otherwise rewrite every user record on the terminal
     * each night, which is a lot of traffic to a small device for no change
     * at all.
     */
    private function apply(BiometricUser $mapping, Member $member, bool $shouldAdmit): bool
    {
        if ($mapping->device === null || ! $mapping->device->is_active) {
            return false;
        }

        if ($mapping->access_enabled === $shouldAdmit) {
            return false;
        }

        $this->drivers->for($mapping->device)->upsertUser(new DeviceUser(
            biometricUserId: $mapping->biometric_user_id,
            name: $mapping->device_user_name ?? $member->full_name,
            enabled: $shouldAdmit,
        ));

        $mapping->forceFill([
            'access_enabled' => $shouldAdmit,
            'sync_status' => 'SYNCED',
            'sync_error' => null,
            'last_synced_at' => now(),
        ])->save();

        return true;
    }
}

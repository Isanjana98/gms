<?php

namespace App\Services\Biometric;

use App\Models\BiometricDevice;
use App\Models\BiometricUser;
use App\Models\Member;
use App\Services\Biometric\DTO\DeviceUser;
use Illuminate\Support\Facades\DB;

/**
 * SRS 8 - Fingerprint registration.
 *
 * Implements the five steps in order: the member already exists, we allocate a
 * biometric user id, put that identity on the reader, store the mapping, and
 * give the administrator a way to confirm the enrolment actually took.
 *
 * The fingerprint itself is enrolled by the member at the reader and never
 * travels: we only ever create the identity for it to attach to (SRS 26).
 */
class EnrollmentService
{
    public function __construct(private readonly DeviceDriverFactory $drivers) {}

    /**
     * SRS 8 steps 2-4 - allocate an id, create the device user, store the map.
     *
     * @param  string|null  $biometricUserId  explicit id, else one is allocated
     */
    public function enroll(Member $member, BiometricDevice $device, ?string $biometricUserId = null): BiometricUser
    {
        $existing = BiometricUser::query()
            ->where('device_id', $device->getKey())
            ->where('member_id', $member->getKey())
            ->first();

        if ($existing !== null && $biometricUserId === null) {
            // Re-enrolling an existing member: reuse their id so the history
            // of scans already recorded against it stays theirs.
            $biometricUserId = $existing->biometric_user_id;
        }

        $mapping = DB::transaction(function () use ($member, $device, $biometricUserId, $existing) {
            $id = $biometricUserId ?? $this->allocateId($device, $member);

            $this->assertIdAvailable($device, $id, $member);

            $mapping = $existing ?? new BiometricUser([
                'device_id' => $device->getKey(),
                'member_id' => $member->getKey(),
            ]);

            $mapping->fill([
                'device_id' => $device->getKey(),
                'member_id' => $member->getKey(),
                'biometric_user_id' => $id,
                'device_user_name' => $member->full_name,
                'enrollment_status' => 'PENDING',
                'sync_status' => 'PENDING',
                'is_active' => true,
            ]);

            $mapping->save();

            return $mapping;
        });

        // Outside the transaction on purpose: the device is a third party and
        // a slow reader must not hold a database transaction open. If this
        // fails the mapping survives as PENDING/FAILED and can be retried.
        try {
            $this->drivers->for($device)->upsertUser(new DeviceUser(
                biometricUserId: $mapping->biometric_user_id,
                name: $member->full_name,
            ));

            $mapping->forceFill([
                'sync_status' => 'SYNCED',
                'sync_error' => null,
                'last_synced_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            $mapping->forceFill([
                'sync_status' => 'FAILED',
                'sync_error' => substr($e->getMessage(), 0, 255),
            ])->save();

            throw new \RuntimeException(
                "Member record saved, but the device rejected the user: {$e->getMessage()}",
                previous: $e,
            );
        }

        return $mapping->refresh();
    }

    /**
     * SRS 8 step 5 - Confirm the fingerprint is really on the device.
     *
     * Asks the reader rather than trusting our own record. Reception routinely
     * believes an enrolment worked because the screen said so, and the only
     * way to know is to look.
     */
    public function verify(BiometricUser $mapping): BiometricUser
    {
        $device = $mapping->device;

        if ($device === null) {
            throw new \RuntimeException('This mapping has no device to verify against.');
        }

        $found = null;

        foreach ($this->drivers->for($device)->fetchUsers() as $deviceUser) {
            if ($deviceUser->biometricUserId === $mapping->biometric_user_id) {
                $found = $deviceUser;
                break;
            }
        }

        if ($found === null) {
            $mapping->forceFill([
                'enrollment_status' => 'FAILED',
                'sync_status' => 'FAILED',
                'sync_error' => 'User not found on the device.',
                'verified_at' => null,
            ])->save();

            return $mapping;
        }

        $mapping->forceFill([
            'device_user_name' => $found->name ?? $mapping->device_user_name,
            'fingerprint_count' => $found->fingerprintCount,
            'has_card' => $found->hasCard,
            'has_face' => $found->hasFace,
            // Present on the device but with no fingerprint recorded means the
            // identity exists and the member never actually placed a finger.
            'enrollment_status' => $found->fingerprintCount > 0 ? 'ENROLLED' : 'PENDING',
            'sync_status' => 'SYNCED',
            'sync_error' => $found->fingerprintCount > 0 ? null : 'No fingerprint enrolled on the device yet.',
            'verified_at' => $found->fingerprintCount > 0 ? now() : null,
            'last_synced_at' => now(),
        ])->save();

        return $mapping->refresh();
    }

    /** Remove the member from the reader and retire the mapping. */
    public function revoke(BiometricUser $mapping): void
    {
        $device = $mapping->device;

        if ($device !== null) {
            try {
                $this->drivers->for($device)->deleteUser($mapping->biometric_user_id);
            } catch (\Throwable $e) {
                // Deactivate locally regardless: a member who must not get in
                // should stop being recognised by our system immediately, even
                // if the reader is offline and has to be cleaned up later.
                $mapping->forceFill([
                    'sync_status' => 'FAILED',
                    'sync_error' => substr('Device removal failed: '.$e->getMessage(), 0, 255),
                ])->save();
            }
        }

        $mapping->forceFill([
            'is_active' => false,
            'enrollment_status' => 'REMOVED',
        ])->save();
    }

    /**
     * SRS 8 step 2 - Allocate a device user id.
     *
     * Numeric and short, because Hikvision employeeNo fields are limited and
     * the number is what a technician reads off the device screen when
     * something needs checking. The member id is the natural candidate: it is
     * already unique and stable for the life of the member.
     */
    private function allocateId(BiometricDevice $device, Member $member): string
    {
        $candidate = (string) $member->getKey();

        $taken = BiometricUser::query()
            ->where('device_id', $device->getKey())
            ->where('biometric_user_id', $candidate)
            ->where('member_id', '!=', $member->getKey())
            ->withTrashed()
            ->exists();

        if (! $taken) {
            return $candidate;
        }

        // Already claimed - usually by a user discovered on the device before
        // this member existed. Take the next free number above the highest.
        // Cast in SQL because the column is a string: '9' sorts above '10'.
        $highest = (int) BiometricUser::query()
            ->where('device_id', $device->getKey())
            ->withTrashed()
            ->selectRaw('MAX(CAST(biometric_user_id AS UNSIGNED)) AS highest')
            ->value('highest');

        return (string) max($highest + 1, (int) $candidate + 1);
    }

    /**
     * An id held by an unmatched device user is free to claim - that is
     * exactly the reconciliation SRS 24 asks for. One held by a different
     * member is not.
     */
    private function assertIdAvailable(BiometricDevice $device, string $id, Member $member): void
    {
        $conflict = BiometricUser::query()
            ->where('device_id', $device->getKey())
            ->where('biometric_user_id', $id)
            ->whereNotNull('member_id')
            ->where('member_id', '!=', $member->getKey())
            ->exists();

        if ($conflict) {
            throw new \RuntimeException(sprintf(
                'Biometric user ID %s on %s is already assigned to another member.',
                $id,
                $device->name,
            ));
        }
    }
}

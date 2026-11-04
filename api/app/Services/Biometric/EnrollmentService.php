<?php

namespace App\Services\Biometric;

use App\Models\BiometricDevice;
use App\Models\BiometricUser;
use App\Models\Member;
use App\Services\Biometric\DTO\DeviceUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

            // An unmatched row already holding this id is the device user we
            // are claiming, not a clash. Taking it over rather than inserting
            // a second row matters for more than the unique index: that row
            // is what the reader's own scans have been filing themselves
            // against, so adopting it is what makes them this member's.
            $unmatched = $existing === null
                ? BiometricUser::query()
                    ->where('device_id', $device->getKey())
                    ->where('biometric_user_id', $id)
                    ->whereNull('member_id')
                    ->withTrashed()
                    ->first()
                : null;

            if ($unmatched !== null) {
                $unmatched->restore();
            }

            $mapping = $existing ?? $unmatched ?? new BiometricUser([
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
                enabled: $this->shouldAdmit($member),
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

    /**
     * Change the id a member is known by on one reader.
     *
     * The reason this exists: the number on the device and the member's id in
     * here drift apart. Somebody enrols a member at the terminal before adding
     * them to the system, or a reader arrives with users already on it, and
     * from then on the desk is reading 47 off a screen while the record says
     * 112. Making them agree by hand means deleting and re-enrolling, which
     * costs the member another trip.
     *
     * **Which way it goes decides whether the fingerprint survives**, and that
     * is the whole of the decision:
     *
     *  - The device already has a user with the new id. Nothing on the reader
     *    needs to change - we simply point the mapping at it, and the finger
     *    already enrolled there keeps working. This is the case worth aiming
     *    for, and it is what happens when you set the system's id to match
     *    what the terminal already shows.
     *
     *  - The device does not have that id. Then it has to be created and the
     *    old one removed, and the fingerprint does not come with it: Hikvision
     *    stores templates against employeeNo and offers no way to move one.
     *    The member must present their finger again. We say so rather than
     *    letting reception find out when somebody cannot get in.
     *
     * `$updateDevice = false` changes nothing on the reader and only re-points
     * our record. That is the case where the terminal is already right and we
     * are the ones out of step - reading 47 off the device screen and typing
     * it in here - and it is also the only option when the reader cannot be
     * reached from the server, which is how a hosted installation with a
     * push-only device works. The caller is asserting the id exists there; we
     * cannot check, so the message says as much.
     *
     * @return array{mapping: BiometricUser, reenrol: bool, message: string}
     */
    public function changeId(BiometricUser $mapping, string $newId, bool $updateDevice = true): array
    {
        $newId = trim($newId);
        $oldId = $mapping->biometric_user_id;

        if ($newId === $oldId) {
            return [
                'mapping' => $mapping,
                'reenrol' => false,
                'message' => "Already using ID {$newId} on {$mapping->device?->name}.",
            ];
        }

        $member = $mapping->member;

        if ($member === null) {
            throw new \RuntimeException('This device user is not matched to a member yet. Match it first.');
        }

        $device = $mapping->device;

        if ($device === null) {
            throw new \RuntimeException('This mapping has no device.');
        }

        $this->assertIdAvailable($device, $newId, $member);

        if (! $updateDevice) {
            $this->repoint($mapping, $member, $device, $newId, reenrol: false, onDevice: null);

            return [
                'mapping' => $mapping->refresh(),
                'reenrol' => false,
                'message' => sprintf(
                    'ID changed from %s to %s in the system only. The reader was not contacted, so %s must already be the number it shows for %s.',
                    $oldId, $newId, $newId, $member->full_name,
                ),
            ];
        }

        $driver = $this->drivers->for($device);

        // Ask the reader, not our own records. A row here saying the id is
        // free proves nothing about what is on the hardware, and getting this
        // backwards is what silently wipes a fingerprint.
        $onDevice = $driver->findUser($newId);
        $reenrol = $onDevice === null;

        if ($reenrol) {
            // Create before destroy. If the create fails we have changed
            // nothing, and the member keeps the access they had.
            $driver->upsertUser(new DeviceUser(
                biometricUserId: $newId,
                name: $member->full_name,
                enabled: $this->shouldAdmit($member),
            ));

            try {
                $driver->deleteUser($oldId);
            } catch (\Throwable $e) {
                // The new identity is live, so the member is not locked out.
                // The old one lingering is untidy rather than dangerous, and
                // saying so is better than unwinding a change that worked.
                Log::warning('Old biometric id left on the device after a change.', [
                    'device' => $device->code,
                    'old_id' => $oldId,
                    'new_id' => $newId,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            // Adopting an identity already on the reader: make sure its name
            // reads as the member, so the terminal's own screen agrees with us.
            $driver->upsertUser(new DeviceUser(
                biometricUserId: $newId,
                name: $member->full_name,
                enabled: $this->shouldAdmit($member),
            ));
        }

        $this->repoint($mapping, $member, $device, $newId, $reenrol, $onDevice);

        return [
            'mapping' => $mapping->refresh(),
            'reenrol' => $reenrol,
            'message' => $reenrol
                ? sprintf(
                    'ID changed from %s to %s on %s. The reader had no user %s, so one was created and the old one removed — %s must enrol their fingerprint again.',
                    $oldId, $newId, $device->name, $newId, $member->full_name,
                )
                : sprintf(
                    'ID changed from %s to %s on %s. The reader already had user %s, so the existing fingerprint still works.',
                    $oldId, $newId, $device->name, $newId,
                ),
        ];
    }

    /**
     * Move our record onto the new id.
     *
     * `$onDevice` is what the reader says about that id, where we were able to
     * ask. Null means either the id is new to the device or we never looked;
     * in both cases the safe reading is that no fingerprint is attached yet,
     * so the mapping goes back to PENDING and reception is told to verify.
     */
    private function repoint(
        BiometricUser $mapping,
        Member $member,
        BiometricDevice $device,
        string $newId,
        bool $reenrol,
        ?DeviceUser $onDevice,
    ): void {
        DB::transaction(function () use ($mapping, $device, $newId, $member, $reenrol, $onDevice) {
            // An unmatched row holding this id is the same device user we are
            // adopting, and the unique index will not take two. It is
            // superseded, not lost: the mapping below inherits its enrolment.
            BiometricUser::query()
                ->where('device_id', $device->getKey())
                ->where('biometric_user_id', $newId)
                ->whereKeyNot($mapping->getKey())
                ->withTrashed()
                ->get()
                ->each
                ->forceDelete();

            $fingerprints = $reenrol ? 0 : ($onDevice?->fingerprintCount ?? 0);

            $mapping->forceFill([
                'biometric_user_id' => $newId,
                'device_user_name' => $member->full_name,
                'fingerprint_count' => $fingerprints,
                'has_card' => $reenrol ? false : ($onDevice?->hasCard ?? false),
                'has_face' => $reenrol ? false : ($onDevice?->hasFace ?? false),
                // Only an answer from the device earns ENROLLED. Assuming it
                // would show a green tick next to a finger nobody has placed.
                'enrollment_status' => $fingerprints > 0 ? 'ENROLLED' : 'PENDING',
                'verified_at' => $fingerprints > 0 ? $mapping->verified_at : null,
                'sync_status' => 'SYNCED',
                'sync_error' => null,
                'last_synced_at' => now(),
            ])->save();
        });
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
     * Whether the reader should currently admit this member.
     *
     * The same two questions the turnstile asks at scan time - is the member
     * themselves permitted, and is there a live term - so the hardware and the
     * software cannot disagree about who is allowed in.
     *
     * The scan-time check stays regardless. This only saves the member the
     * walk to a door that was never going to open, and keeps the terminal's
     * own user list honest for anyone reading it off the device.
     */
    public function shouldAdmit(Member $member): bool
    {
        if (! $member->status->allowsEntry()) {
            return false;
        }

        return $member->currentMembership?->allowsEntryOn() === true;
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

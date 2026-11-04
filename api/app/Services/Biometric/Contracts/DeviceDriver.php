<?php

namespace App\Services\Biometric\Contracts;

use App\Models\BiometricDevice;
use App\Services\Biometric\DTO\DeviceEvent;
use App\Services\Biometric\DTO\DeviceInfo;
use App\Services\Biometric\DTO\DeviceUser;
use Carbon\CarbonInterface;

/**
 * SRS 35 - Everything vendor-specific lives behind this interface.
 *
 * The SRS is explicit that the exact reader is not yet confirmed, so nothing
 * above this line knows what protocol is in use. Supporting a second vendor
 * means writing one class and adding it to config('gms.biometric.drivers').
 *
 * Implementations must not throw for an unreachable device on testConnection()
 * - an offline reader is an expected state, not an exception (SRS 14).
 */
interface DeviceDriver
{
    public function __construct(BiometricDevice $device);

    /** SRS 23 - Test connection. Never throws; reports failure in the DTO. */
    public function testConnection(): DeviceInfo;

    /**
     * SRS 13 - Attendance logs in the window, oldest first.
     *
     * Returns a generator so a reader with months of buffered offline events
     * can be drained without loading all of them into memory at once.
     *
     * @return iterable<DeviceEvent>
     */
    public function fetchEvents(CarbonInterface $from, CarbonInterface $to): iterable;

    /**
     * SRS 24 - Download the users registered on the device.
     *
     * @return iterable<DeviceUser>
     */
    public function fetchUsers(): iterable;

    /**
     * SRS 24 - Create or update a user on the device. Fingerprints are still
     * enrolled at the reader; this only puts the identity there to attach
     * them to.
     */
    public function upsertUser(DeviceUser $user): bool;

    /**
     * Look one user up by the id the device knows them by.
     *
     * Returns null when the device has no such user. Separate from
     * fetchUsers() because the two questions have different costs: checking
     * whether one id is taken should not drain a reader holding a thousand
     * records.
     */
    public function findUser(string $biometricUserId): ?DeviceUser;

    public function deleteUser(string $biometricUserId): bool;

    /**
     * Release the door lock now.
     *
     * For the case the fingerprint reader cannot help with: a member whose
     * finger will not read, a visitor, a delivery. Every call is audited by
     * the caller, because "the door opened and no scan explains it" is a
     * question somebody will eventually ask.
     */
    public function openDoor(int $doorNumber = 1): bool;

    /**
     * Interpret a device-initiated push notification.
     *
     * Returns null when the payload is a heartbeat or any other message that
     * is not a scan - readers send plenty of those.
     *
     * @param  array<mixed>  $payload
     */
    public function parsePushEvent(array $payload): ?DeviceEvent;
}

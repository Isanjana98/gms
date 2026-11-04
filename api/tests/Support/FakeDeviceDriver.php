<?php

namespace Tests\Support;

use App\Models\BiometricDevice;
use App\Services\Biometric\Contracts\DeviceDriver;
use App\Services\Biometric\DTO\DeviceEvent;
use App\Services\Biometric\DTO\DeviceInfo;
use App\Services\Biometric\DTO\DeviceUser;
use Carbon\CarbonInterface;

/**
 * A reader that exists only in memory.
 *
 * Lets the sync, recovery and deduplication logic be tested for real without
 * any hardware - which is the point of the driver contract in SRS 35.
 */
class FakeDeviceDriver implements DeviceDriver
{
    /** @var array<int, DeviceEvent> */
    public static array $events = [];

    /** @var array<int, DeviceUser> */
    public static array $users = [];

    public static ?string $throwOnFetch = null;

    /** @var array<int, string> */
    public static array $uploaded = [];

    /** @var array<int, string> */
    public static array $deleted = [];

    public static array $windows = [];

    /** @var array<int, array{id: string, enabled: bool}> */
    public static array $upsertedWith = [];

    /** @var array<int, int> */
    public static array $doorsOpened = [];

    public static ?string $throwOnOpenDoor = null;

    /** Biometric user id whose upsert should fail, as an offline reader would. */
    public static ?string $throwOnUpsertFor = null;

    public function __construct(private readonly BiometricDevice $device) {}

    public static function reset(): void
    {
        self::$events = [];
        self::$users = [];
        self::$uploaded = [];
        self::$upsertedWith = [];
        self::$deleted = [];
        self::$windows = [];
        self::$doorsOpened = [];
        self::$throwOnFetch = null;
        self::$throwOnOpenDoor = null;
        self::$throwOnUpsertFor = null;
    }

    public function testConnection(): DeviceInfo
    {
        return new DeviceInfo(true, model: 'FakeReader', serialNumber: 'FAKE-1', firmware: 'v1.0');
    }

    public function fetchEvents(CarbonInterface $from, CarbonInterface $to): iterable
    {
        self::$windows[] = [$from->toDateTimeString(), $to->toDateTimeString()];

        if (self::$throwOnFetch !== null) {
            throw new \RuntimeException(self::$throwOnFetch);
        }

        // Mirror a real device: only events inside the requested window.
        foreach (self::$events as $event) {
            if ($event->occurredAt->betweenIncluded($from, $to)) {
                yield $event;
            }
        }
    }

    public function fetchUsers(): iterable
    {
        return self::$users;
    }

    public function upsertUser(DeviceUser $user): bool
    {
        if (self::$throwOnUpsertFor === $user->biometricUserId) {
            throw new \RuntimeException('Device unreachable.');
        }

        self::$uploaded[] = $user->biometricUserId;
        self::$upsertedWith[] = ['id' => $user->biometricUserId, 'enabled' => $user->enabled];

        // Behave like a device: the user is there afterwards, so a findUser()
        // that follows an upsert tells the truth.
        self::$users = array_values(array_filter(
            self::$users,
            fn (DeviceUser $existing) => $existing->biometricUserId !== $user->biometricUserId,
        ));
        self::$users[] = $user;

        return true;
    }

    public function findUser(string $biometricUserId): ?DeviceUser
    {
        foreach (self::$users as $user) {
            if ($user->biometricUserId === $biometricUserId) {
                return $user;
            }
        }

        return null;
    }

    public function deleteUser(string $biometricUserId): bool
    {
        self::$deleted[] = $biometricUserId;

        self::$users = array_values(array_filter(
            self::$users,
            fn (DeviceUser $user) => $user->biometricUserId !== $biometricUserId,
        ));

        return true;
    }

    public function openDoor(int $doorNumber = 1): bool
    {
        if (self::$throwOnOpenDoor !== null) {
            throw new \RuntimeException(self::$throwOnOpenDoor);
        }

        self::$doorsOpened[] = $doorNumber;

        return true;
    }

    public function parsePushEvent(array $payload): ?DeviceEvent
    {
        return self::$events[0] ?? null;
    }
}

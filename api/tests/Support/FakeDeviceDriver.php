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

    public function __construct(private readonly BiometricDevice $device) {}

    public static function reset(): void
    {
        self::$events = [];
        self::$users = [];
        self::$uploaded = [];
        self::$deleted = [];
        self::$windows = [];
        self::$throwOnFetch = null;
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
        self::$uploaded[] = $user->biometricUserId;

        return true;
    }

    public function deleteUser(string $biometricUserId): bool
    {
        self::$deleted[] = $biometricUserId;

        return true;
    }

    public function parsePushEvent(array $payload): ?DeviceEvent
    {
        return self::$events[0] ?? null;
    }
}

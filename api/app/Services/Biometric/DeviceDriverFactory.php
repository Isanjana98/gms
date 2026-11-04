<?php

namespace App\Services\Biometric;

use App\Models\BiometricDevice;
use App\Services\Biometric\Contracts\DeviceDriver;

/**
 * Resolves the driver for a device (SRS 35).
 *
 * Instances are cached per device for the life of the request so a sync run
 * that touches the same reader repeatedly does not rebuild its HTTP client
 * each time.
 */
class DeviceDriverFactory
{
    /** @var array<int, DeviceDriver> */
    private array $cache = [];

    public function for(BiometricDevice $device): DeviceDriver
    {
        $key = $device->getKey();

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $drivers = config('gms.biometric.drivers', []);
        $class = $drivers[$device->driver] ?? null;

        if ($class === null) {
            throw new \RuntimeException(sprintf(
                'No biometric driver registered for "%s". Registered drivers: %s.',
                $device->driver,
                implode(', ', array_keys($drivers)) ?: 'none',
            ));
        }

        if (! is_subclass_of($class, DeviceDriver::class)) {
            throw new \RuntimeException("Driver {$class} must implement ".DeviceDriver::class.'.');
        }

        return $this->cache[$key] = new $class($device);
    }

    /** @return array<int, string> */
    public function available(): array
    {
        return array_keys(config('gms.biometric.drivers', []));
    }
}

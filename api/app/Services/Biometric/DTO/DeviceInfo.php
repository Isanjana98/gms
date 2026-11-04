<?php

namespace App\Services\Biometric\DTO;

/** What a reader reports about itself on a successful connection test. */
readonly class DeviceInfo
{
    public function __construct(
        public bool $reachable,
        public ?string $model = null,
        public ?string $serialNumber = null,
        public ?string $firmware = null,
        public ?string $deviceName = null,
        public ?string $error = null,
        public array $raw = [],
    ) {}

    public static function failed(string $error): self
    {
        return new self(false, error: $error);
    }
}

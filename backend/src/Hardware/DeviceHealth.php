<?php

declare(strict_types=1);

namespace App\Hardware;

final readonly class DeviceHealth
{
    public function __construct(
        public string $status,
        public DeviceAvailability $availability,
        public ?HardwareError $error,
    ) {
    }

    public static function healthy(): self
    {
        return new self('healthy', DeviceAvailability::Available, null);
    }

    public static function unhealthy(DeviceAvailability $availability, HardwareError $error): self
    {
        return new self('unhealthy', $availability, $error);
    }
}

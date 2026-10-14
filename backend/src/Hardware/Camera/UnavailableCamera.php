<?php

declare(strict_types=1);

namespace App\Hardware\Camera;

use App\Hardware\DeviceAvailability;
use App\Hardware\DeviceHealth;
use App\Hardware\HardwareError;
use App\Hardware\HardwareException;

final class UnavailableCamera implements CameraDevice
{
    public function availability(): DeviceAvailability
    {
        return DeviceAvailability::Unavailable;
    }

    public function health(): DeviceHealth
    {
        return DeviceHealth::unhealthy(DeviceAvailability::Unavailable, HardwareError::DeviceUnavailable);
    }

    public function capture(string $attemptId): CapturedPhotograph
    {
        throw new HardwareException(HardwareError::DeviceUnavailable);
    }
}

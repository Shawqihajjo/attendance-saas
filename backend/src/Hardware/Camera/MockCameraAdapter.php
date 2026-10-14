<?php

declare(strict_types=1);

namespace App\Hardware\Camera;

use App\Hardware\DeviceAvailability;
use App\Hardware\DeviceHealth;
use App\Hardware\HardwareError;
use App\Hardware\HardwareException;

final class MockCameraAdapter implements CameraDevice
{
    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\xFF\xD9";

    public function __construct(
        private readonly ?HardwareError $scriptedFault = null,
    ) {
    }

    public function availability(): DeviceAvailability
    {
        return match ($this->scriptedFault) {
            HardwareError::DeviceDisconnected => DeviceAvailability::Disconnected,
            HardwareError::DeviceUnavailable => DeviceAvailability::Unavailable,
            default => DeviceAvailability::Available,
        };
    }

    public function health(): DeviceHealth
    {
        $availability = $this->availability();
        if ($availability === DeviceAvailability::Available && !$this->scriptedFault instanceof HardwareError) {
            return DeviceHealth::healthy();
        }

        return DeviceHealth::unhealthy($availability, $this->scriptedFault ?? HardwareError::DeviceUnavailable);
    }

    public function capture(string $attemptId): CapturedPhotograph
    {
        if ($attemptId === '' || $this->scriptedFault instanceof HardwareError) {
            throw new HardwareException($this->scriptedFault ?? HardwareError::CaptureFailed);
        }

        return new CapturedPhotograph('image/jpeg', self::JPEG);
    }
}

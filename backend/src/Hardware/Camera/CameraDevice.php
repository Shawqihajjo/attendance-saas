<?php

declare(strict_types=1);

namespace App\Hardware\Camera;

use App\Hardware\DeviceAvailability;
use App\Hardware\DeviceHealth;
use App\Hardware\HardwareException;

/**
 * Camera port. The terminal captures the photograph. This port is the same contract
 * for an in-process adapter and for tests. Attendance stores a photo id, not a driver.
 */
interface CameraDevice
{
    public function availability(): DeviceAvailability;

    public function health(): DeviceHealth;

    /**
     * @throws HardwareException
     */
    public function capture(string $attemptId): CapturedPhotograph;
}

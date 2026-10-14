<?php

declare(strict_types=1);

namespace App\Hardware\Fingerprint;

use App\Hardware\DeviceAvailability;
use App\Hardware\DeviceHealth;
use App\Hardware\EnrollmentStatus;
use App\Hardware\HardwareError;
use Symfony\Component\Uid\Uuid;

final class UnavailableFingerprintReader implements FingerprintReader
{
    public function verify(string $employeeId, BiometricClaim $claim): ReaderVerification
    {
        return new ReaderVerification(false, $claim->vendor, Uuid::v7()->toRfc4122(), HardwareError::DeviceUnavailable);
    }

    public function enrollmentStatus(string $employeeId): EnrollmentStatus
    {
        return $employeeId === '' ? EnrollmentStatus::NotEnrolled : EnrollmentStatus::Unknown;
    }

    public function availability(): DeviceAvailability
    {
        return DeviceAvailability::Unavailable;
    }

    public function health(): DeviceHealth
    {
        return DeviceHealth::unhealthy(DeviceAvailability::Unavailable, HardwareError::DeviceUnavailable);
    }
}

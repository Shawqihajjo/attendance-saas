<?php

declare(strict_types=1);

namespace App\Hardware\Fingerprint;

use App\Hardware\DeviceAvailability;
use App\Hardware\DeviceHealth;
use App\Hardware\EnrollmentStatus;
use App\Hardware\HardwareError;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Development and test stand-in. It accepts one configured sample and stores nothing.
 */
final class MockFingerprintAdapter implements FingerprintReader
{
    public const VENDOR = 'mock';
    public const ACCEPTED_SAMPLE = 'dev-verify';

    public function __construct(
        #[Autowire('%kernel.environment%')]
        string $environment,
        private readonly ?HardwareError $scriptedFault = null,
    ) {
        if ($environment === 'prod') {
            throw new \RuntimeException('The mock fingerprint adapter cannot run in production.');
        }
    }

    public function verify(string $employeeId, BiometricClaim $claim): ReaderVerification
    {
        if ($employeeId === '' || $this->scriptedFault instanceof HardwareError) {
            return $this->rejected($claim->vendor, $this->scriptedFault ?? HardwareError::FingerprintMismatch);
        }
        $accepted = $claim->vendor === self::VENDOR && hash_equals(self::ACCEPTED_SAMPLE, $claim->payload());

        return $accepted
            ? new ReaderVerification(true, self::VENDOR, Uuid::v7()->toRfc4122(), null)
            : $this->rejected($claim->vendor, HardwareError::FingerprintMismatch);
    }

    public function enrollmentStatus(string $employeeId): EnrollmentStatus
    {
        return $employeeId === '' ? EnrollmentStatus::NotEnrolled : EnrollmentStatus::Unknown;
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
        $error = $this->scriptedFault ?? HardwareError::DeviceUnavailable;

        return DeviceHealth::unhealthy($availability, $error);
    }

    private function rejected(string $vendor, HardwareError $error): ReaderVerification
    {
        return new ReaderVerification(false, $vendor, Uuid::v7()->toRfc4122(), $error);
    }
}

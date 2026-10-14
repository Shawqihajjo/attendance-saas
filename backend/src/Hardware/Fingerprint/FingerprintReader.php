<?php

declare(strict_types=1);

namespace App\Hardware\Fingerprint;

use App\Hardware\DeviceAvailability;
use App\Hardware\DeviceHealth;
use App\Hardware\EnrollmentStatus;

/**
 * Server-side fingerprint port. A vendor SDK implements this from its own namespace.
 * The attendance domain depends on FingerprintMatcher, which delegates here.
 */
interface FingerprintReader
{
    public function verify(string $employeeId, BiometricClaim $claim): ReaderVerification;

    public function enrollmentStatus(string $employeeId): EnrollmentStatus;

    public function availability(): DeviceAvailability;

    public function health(): DeviceHealth;
}

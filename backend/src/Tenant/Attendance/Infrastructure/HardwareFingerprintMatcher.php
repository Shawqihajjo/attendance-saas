<?php

declare(strict_types=1);

namespace App\Tenant\Attendance\Infrastructure;

use App\Hardware\Fingerprint\BiometricClaim;
use App\Hardware\Fingerprint\FingerprintReader;
use App\Hardware\HardwareError;
use App\Tenant\Attendance\FingerprintMatcher;
use App\Tenant\Attendance\FingerprintVerification;

/**
 * Attendance calls this matcher. The vendor adapter stays behind FingerprintReader.
 */
final class HardwareFingerprintMatcher implements FingerprintMatcher
{
    public function __construct(private readonly FingerprintReader $reader)
    {
    }

    public function verify(string $employeeId, string $vendor, string $sample): FingerprintVerification
    {
        $result = $this->reader->verify($employeeId, new BiometricClaim($vendor, $sample));

        return new FingerprintVerification(
            $result->accepted,
            $result->vendor,
            $result->verificationId,
            $result->accepted ? 'matched' : ($result->error ?? HardwareError::FingerprintMismatch)->value,
        );
    }
}

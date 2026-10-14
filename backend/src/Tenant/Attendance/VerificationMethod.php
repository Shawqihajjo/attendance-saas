<?php

declare(strict_types=1);

namespace App\Tenant\Attendance;

/**
 * Identification and biometric checks are separate methods.
 * Add a case here when a new check is introduced, then teach {@see VerificationPolicy} whether it can accept an event.
 */
enum VerificationMethod: string
{
    case Code = 'code';
    case Fingerprint = 'fingerprint';

    public static function fromString(string $value): self
    {
        return self::tryFrom($value) ?? throw new AttendanceRejectedException(
            'verification_unknown',
            'The verification method is not supported.',
        );
    }
}

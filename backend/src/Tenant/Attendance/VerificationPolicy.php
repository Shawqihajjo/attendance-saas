<?php

declare(strict_types=1);

namespace App\Tenant\Attendance;

final class VerificationPolicy
{
    public function assertAccepted(VerificationMethod $method): void
    {
        if ($method === VerificationMethod::Fingerprint) {
            return;
        }

        throw new AttendanceRejectedException(
            'verification_insufficient',
            'The attendance code identifies the employee. Fingerprint verification is required before an attendance event is recorded.',
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tenant\Attendance;

interface FingerprintMatcher
{
    public function verify(string $employeeId, string $vendor, string $sample): FingerprintVerification;
}

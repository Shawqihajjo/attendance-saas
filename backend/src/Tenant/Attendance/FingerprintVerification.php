<?php

declare(strict_types=1);

namespace App\Tenant\Attendance;

final readonly class FingerprintVerification
{
    public function __construct(
        public bool $accepted,
        public string $vendor,
        public string $verificationId,
        public string $reason,
    ) {
    }
}

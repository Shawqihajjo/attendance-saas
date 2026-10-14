<?php

declare(strict_types=1);

namespace App\Hardware\Fingerprint;

use App\Hardware\HardwareError;

final readonly class ReaderVerification
{
    public function __construct(
        public bool $accepted,
        public string $vendor,
        public string $verificationId,
        public ?HardwareError $error,
    ) {
    }
}

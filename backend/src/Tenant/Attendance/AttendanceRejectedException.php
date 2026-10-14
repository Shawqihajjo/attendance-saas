<?php

declare(strict_types=1);

namespace App\Tenant\Attendance;

final class AttendanceRejectedException extends \RuntimeException
{
    public function __construct(
        private readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}

<?php

declare(strict_types=1);

namespace App\Tenant\Attendance;

final class AttendanceDuration
{
    public static function seconds(\DateTimeImmutable $start, \DateTimeImmutable $end): int
    {
        return $end->getTimestamp() - $start->getTimestamp();
    }
}

<?php

declare(strict_types=1);

namespace App\Tenant\Attendance;

enum AttendanceState: string
{
    case Off = 'off';
    case Working = 'working';
    case OnLunch = 'on_lunch';
}

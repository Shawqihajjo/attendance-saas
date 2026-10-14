<?php

declare(strict_types=1);

namespace App\Hardware;

enum EnrollmentStatus: string
{
    case Enrolled = 'enrolled';
    case NotEnrolled = 'not_enrolled';
    case Unknown = 'unknown';
}

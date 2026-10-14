<?php

declare(strict_types=1);

namespace App\Integration;

use App\Platform\TenantRegistry\Domain\InvalidTenantInputException;

enum DomainEventName: string
{
    case EmployeeClockedIn = 'EmployeeClockedIn';
    case LunchStarted = 'LunchStarted';
    case LunchEnded = 'LunchEnded';
    case EmployeeClockedOut = 'EmployeeClockedOut';
    case AttendanceCorrectionRequested = 'AttendanceCorrectionRequested';
    case AttendanceCorrectionApproved = 'AttendanceCorrectionApproved';
    case AttendanceCorrectionRejected = 'AttendanceCorrectionRejected';
    case TerminalRegistered = 'TerminalRegistered';
    case TerminalSuspended = 'TerminalSuspended';

    public static function require(string $name): self
    {
        return self::tryFrom($name) ?? throw new InvalidTenantInputException('The event name is not supported.');
    }
}

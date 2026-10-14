<?php

declare(strict_types=1);

namespace App\Tenant\Attendance;

final class AttendanceMachine
{
    /**
     * @param list<AttendanceAction> $actions
     */
    public static function reduce(array $actions): AttendanceState
    {
        $state = AttendanceState::Off;
        foreach ($actions as $action) {
            $state = $action->next($state);
        }

        return $state;
    }
}

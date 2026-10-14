<?php

declare(strict_types=1);

namespace App\Tenant\Attendance;

final class AllowedAttendanceActions
{
    /**
     * @return list<array{action: string, photoRequired: bool}>
     */
    public static function forState(AttendanceState $state, bool $photoRequired): array
    {
        $actions = match ($state) {
            AttendanceState::Off => [AttendanceAction::ClockIn],
            AttendanceState::Working => [AttendanceAction::LunchStart, AttendanceAction::ClockOut],
            AttendanceState::OnLunch => [AttendanceAction::LunchEnd],
        };
        $allowed = [];
        foreach ($actions as $action) {
            $allowed[] = [
                'action' => $action->value,
                'photoRequired' => $action === AttendanceAction::ClockIn && $photoRequired,
            ];
        }

        return $allowed;
    }
}

<?php

declare(strict_types=1);

namespace App\Tenant\Attendance;

enum AttendanceAction: string
{
    case ClockIn = 'clock_in';
    case LunchStart = 'lunch_start';
    case LunchEnd = 'lunch_end';
    case ClockOut = 'clock_out';

    public static function fromString(string $value): self
    {
        return self::tryFrom($value) ?? throw new AttendanceRejectedException(
            'unknown_action',
            'The attendance action is not supported.',
        );
    }

    public function next(AttendanceState $from): AttendanceState
    {
        $to = match ([$from, $this]) {
            [AttendanceState::Off, self::ClockIn] => AttendanceState::Working,
            [AttendanceState::Working, self::LunchStart] => AttendanceState::OnLunch,
            [AttendanceState::OnLunch, self::LunchEnd] => AttendanceState::Working,
            [AttendanceState::Working, self::ClockOut] => AttendanceState::Off,
            default => null,
        };
        if (!$to instanceof AttendanceState) {
            throw new AttendanceRejectedException(
                'illegal_transition',
                sprintf('Cannot %s while %s.', $this->value, $from->value),
            );
        }

        return $to;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Tenant\Attendance\AttendanceAction;
use App\Tenant\Attendance\AttendanceDuration;
use App\Tenant\Attendance\AttendanceMachine;
use App\Tenant\Attendance\AttendanceRejectedException;
use App\Tenant\Attendance\AttendanceState;
use App\Tenant\Attendance\VerificationMethod;
use App\Tenant\Attendance\VerificationPolicy;
use PHPUnit\Framework\TestCase;

final class AttendanceStateMachineTest extends TestCase
{
    public function testTheLegalCycleReturnsToOff(): void
    {
        $state = AttendanceMachine::reduce([
            AttendanceAction::ClockIn,
            AttendanceAction::LunchStart,
            AttendanceAction::LunchEnd,
            AttendanceAction::ClockOut,
        ]);

        self::assertSame(AttendanceState::Off, $state);
        self::assertSame(AttendanceState::Working, AttendanceAction::ClockIn->next(AttendanceState::Off));
        self::assertSame(AttendanceState::OnLunch, AttendanceAction::LunchStart->next(AttendanceState::Working));
        self::assertSame(AttendanceState::Working, AttendanceAction::LunchEnd->next(AttendanceState::OnLunch));
        self::assertSame(AttendanceState::Off, AttendanceAction::ClockOut->next(AttendanceState::Working));
    }

    public function testIllegalTransitionsAreRejected(): void
    {
        $cases = [
            [AttendanceState::Off, AttendanceAction::ClockOut],
            [AttendanceState::Off, AttendanceAction::LunchStart],
            [AttendanceState::Off, AttendanceAction::LunchEnd],
            [AttendanceState::Working, AttendanceAction::ClockIn],
            [AttendanceState::Working, AttendanceAction::LunchEnd],
            [AttendanceState::OnLunch, AttendanceAction::LunchStart],
            [AttendanceState::OnLunch, AttendanceAction::ClockIn],
            [AttendanceState::OnLunch, AttendanceAction::ClockOut],
        ];
        foreach ($cases as [$from, $action]) {
            try {
                $action->next($from);
                self::fail($action->value.' from '.$from->value.' was accepted.');
            } catch (AttendanceRejectedException $exception) {
                self::assertSame('illegal_transition', $exception->reason());
            }
        }
    }

    public function testCodeAloneCannotAcceptAnEventAndUnknownMethodsStayClosed(): void
    {
        $policy = new VerificationPolicy();
        $policy->assertAccepted(VerificationMethod::Fingerprint);
        $rejected = VerificationMethod::fromString('code');
        self::assertSame(VerificationMethod::Code, $rejected);
        try {
            $policy->assertAccepted($rejected);
            self::fail('A code was accepted as biometric verification.');
        } catch (AttendanceRejectedException $exception) {
            self::assertSame('verification_insufficient', $exception->reason());
        }
        try {
            VerificationMethod::fromString('face');
            self::fail('An unknown verification method was accepted.');
        } catch (AttendanceRejectedException $exception) {
            self::assertSame('verification_unknown', $exception->reason());
        }
    }

    public function testDurationsUseUtcInstantsAcrossMidnightAndDaylightSaving(): void
    {
        $utc = new \DateTimeZone('UTC');
        $stockholm = new \DateTimeZone('Europe/Stockholm');

        $beforeMidnight = new \DateTimeImmutable('2026-01-15 23:30:00', $utc);
        $afterMidnight = new \DateTimeImmutable('2026-01-16 00:30:00', $utc);
        self::assertSame(3600, AttendanceDuration::seconds($beforeMidnight, $afterMidnight));
        self::assertNotSame($beforeMidnight->format('Y-m-d'), $afterMidnight->format('Y-m-d'));
        self::assertSame('2026-01-16', $beforeMidnight->setTimezone($stockholm)->format('Y-m-d'));

        $springStart = new \DateTimeImmutable('2026-03-29 00:30:00', $utc);
        $springEnd = new \DateTimeImmutable('2026-03-29 01:30:00', $utc);
        self::assertSame('01:30', $springStart->setTimezone($stockholm)->format('H:i'));
        self::assertSame('03:30', $springEnd->setTimezone($stockholm)->format('H:i'));
        self::assertSame(3600, AttendanceDuration::seconds($springStart, $springEnd));

        $fallStart = new \DateTimeImmutable('2026-10-25 00:30:00', $utc);
        $fallEnd = new \DateTimeImmutable('2026-10-25 01:30:00', $utc);
        self::assertSame(
            $fallStart->setTimezone($stockholm)->format('H:i'),
            $fallEnd->setTimezone($stockholm)->format('H:i'),
        );
        self::assertSame(3600, AttendanceDuration::seconds($fallStart, $fallEnd));
        self::assertSame(3600, AttendanceDuration::seconds(
            $fallStart->setTimezone($stockholm),
            $fallEnd->setTimezone($stockholm),
        ));
    }
}

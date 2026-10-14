<?php

declare(strict_types=1);

use App\Kernel;
use App\Tenant\Attendance\AttendanceAction;
use App\Tenant\Attendance\AttendanceRejectedException;
use App\Tenant\Attendance\Infrastructure\AttendanceLedger;
use App\Tenant\Attendance\RecordAttendance;
use App\Tenant\Attendance\VerificationMethod;
use App\TenantContext\Domain\TenantDatabaseSwitcher;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');

$tenantId = $argv[1] ?? '';
$employeeId = $argv[2] ?? '';
$deviceId = $argv[3] ?? '';
$idempotencyKey = $argv[4] ?? '';
$photoId = $argv[5] ?? '';

$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$switcher = $container->get(TenantDatabaseSwitcher::class);
$ledger = $container->get(AttendanceLedger::class);

$switcher->switchTo($tenantId);
try {
    $ledger->commit(new RecordAttendance(
        $employeeId,
        AttendanceAction::ClockIn,
        VerificationMethod::Fingerprint,
        $deviceId,
        $photoId,
        $idempotencyKey,
        true,
    ), new DateTimeImmutable('now', new DateTimeZone('UTC')));
    echo "accepted\n";
} catch (AttendanceRejectedException $exception) {
    echo 'rejected '.$exception->reason()."\n";
} finally {
    $kernel->shutdown();
}

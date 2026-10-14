<?php

declare(strict_types=1);

use App\Kernel;
use App\Platform\TenantRegistry\Domain\TenantQuotaExceededException;
use App\Tenant\Directory\Infrastructure\EmployeeDirectory;
use App\TenantContext\Domain\TenantDatabaseSwitcher;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$tenantId = $argv[1] ?? '';
$number = $argv[2] ?? '';
if ($tenantId === '' || $number === '') {
    fwrite(STDERR, "Tenant and employee number are required.\n");
    echo "ERROR\n";
    exit(1);
}

$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$switcher = $container->get(TenantDatabaseSwitcher::class);
$directory = $container->get(EmployeeDirectory::class);

try {
    $switcher->switchTo($tenantId);
    $directory->create([
        'employeeNumber' => $number,
        'name' => 'Quota Racer',
    ], new DateTimeImmutable('now', new \DateTimeZone('UTC')));
    echo "CREATED\n";
} catch (TenantQuotaExceededException) {
    echo "QUOTA\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.' '.$exception->getMessage()."\n");
    echo "ERROR\n";
} finally {
    $switcher->reset();
}

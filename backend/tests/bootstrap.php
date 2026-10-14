<?php

use App\Kernel;
use App\Platform\TenantRegistry\Infrastructure\Migrations\DoctrineMigrationRunner;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

if (($_SERVER['APP_ENV'] ?? null) === 'test') {
    $kernel = new Kernel('test', true);
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    $runner = $container->get(DoctrineMigrationRunner::class);
    if (!$runner instanceof DoctrineMigrationRunner) {
        throw new RuntimeException('The platform migration runner is unavailable.');
    }
    $runner->migratePlatform();
    $kernel->shutdown();
}

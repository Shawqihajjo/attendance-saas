<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\TenantContext\Domain\TenantDatabaseNotSelectedException;
use App\TenantContext\Infrastructure\Doctrine\TenantAwareConnection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class TenantAwareConnectionTest extends TestCase
{
    public function testConnectionRefusesSqlUntilATenantIsSelected(): void
    {
        $first = tempnam(sys_get_temp_dir(), 'tenant-a-');
        $second = tempnam(sys_get_temp_dir(), 'tenant-b-');
        self::assertIsString($first);
        self::assertIsString($second);

        try {
            $connection = DriverManager::getConnection([
                'driver' => 'pdo_sqlite',
                'path' => $first,
                'wrapperClass' => TenantAwareConnection::class,
            ]);
            self::assertInstanceOf(TenantAwareConnection::class, $connection);

            $this->expectException(TenantDatabaseNotSelectedException::class);
            $connection->executeStatement('CREATE TABLE marker (id INTEGER)');
        } finally {
            @unlink($first);
            @unlink($second);
        }
    }

    public function testSwitchingDatabasesDoesNotKeepThePreviousDatabase(): void
    {
        $first = tempnam(sys_get_temp_dir(), 'tenant-a-');
        $second = tempnam(sys_get_temp_dir(), 'tenant-b-');
        self::assertIsString($first);
        self::assertIsString($second);

        try {
            $connection = DriverManager::getConnection([
                'driver' => 'pdo_sqlite',
                'path' => $first,
                'wrapperClass' => TenantAwareConnection::class,
            ]);
            self::assertInstanceOf(TenantAwareConnection::class, $connection);

            $connection->selectDatabase(['path' => $first]);
            $connection->executeStatement('CREATE TABLE marker (id INTEGER)');
            $connection->executeStatement('INSERT INTO marker (id) VALUES (1)');

            $connection->selectDatabase(['path' => $second]);
            $tables = $connection->executeQuery("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'marker'")->fetchFirstColumn();
            self::assertSame([], $tables);

            $connection->selectDatabase(['path' => $first]);
            $count = $connection->executeQuery('SELECT COUNT(*) FROM marker')->fetchOne();
            self::assertSame('1', (string) $count);

            $connection->release();
            self::assertFalse($connection->isTenantSelected());
            $this->expectException(TenantDatabaseNotSelectedException::class);
            $connection->executeQuery('SELECT 1');
        } finally {
            @unlink($first);
            @unlink($second);
        }
    }
}

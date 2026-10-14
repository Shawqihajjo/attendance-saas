<?php

declare(strict_types=1);

namespace App\Shared\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\ServerVersionProvider;

final class UtcTimeZoneDriver implements Driver
{
    public function __construct(private readonly Driver $driver)
    {
    }

    public function connect(array $params): DriverConnection
    {
        $connection = $this->driver->connect($params);
        if (($params['driver'] ?? null) === 'pdo_mysql') {
            $connection->exec("SET time_zone = '+00:00'");
        }

        return $connection;
    }

    public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
    {
        return $this->driver->getDatabasePlatform($versionProvider);
    }

    public function getExceptionConverter(): ExceptionConverter
    {
        return $this->driver->getExceptionConverter();
    }
}

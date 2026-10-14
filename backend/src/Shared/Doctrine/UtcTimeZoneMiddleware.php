<?php

declare(strict_types=1);

namespace App\Shared\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

#[AsMiddleware(connections: ['platform'])]
final class UtcTimeZoneMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new UtcTimeZoneDriver($driver);
    }
}

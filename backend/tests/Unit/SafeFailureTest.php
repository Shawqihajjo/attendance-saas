<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Platform\TenantRegistry\Application\SafeFailure;
use PHPUnit\Framework\TestCase;

final class SafeFailureTest extends TestCase
{
    public function testSecretMaterialIsReplacedBeforeItCanBeStored(): void
    {
        self::assertSame(
            'The operation could not be completed.',
            SafeFailure::reason(new \RuntimeException('password was rejected')),
        );
        self::assertSame(
            'The tenant database could not be created.',
            SafeFailure::reason(new \RuntimeException('The tenant database could not be created.')),
        );
        self::assertSame(
            'The operation could not be completed.',
            SafeFailure::reason(new \PDOException("SQLSTATE[HY000] [1045] Access denied for user 'tu_example'@'localhost'")),
        );
        self::assertSame(
            'The operation could not be completed.',
            SafeFailure::reason(new \RuntimeException('sqlite:/var/tenant/tenant_example.sqlite is missing')),
        );
    }
}

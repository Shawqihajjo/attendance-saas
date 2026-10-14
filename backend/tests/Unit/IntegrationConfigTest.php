<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Integration\IntegrationConfig;
use App\Platform\TenantRegistry\Domain\InvalidTenantInputException;
use PHPUnit\Framework\TestCase;

final class IntegrationConfigTest extends TestCase
{
    public function testSupportedConfigurationIsData(): void
    {
        $config = new IntegrationConfig();

        self::assertSame(
            ['url' => 'https://hooks.example.com/attendance'],
            $config->validate('webhook', 'EmployeeClockedIn', ['url' => 'https://hooks.example.com/attendance']),
        );
        self::assertSame(['audience' => 'tenant'], $config->validate('notification', '*', ['audience' => 'tenant']));
        self::assertSame(['to' => 'ops@example.com'], $config->validate('email', 'EmployeeClockedOut', ['to' => 'Ops@Example.com']));
        self::assertSame(['handler' => 'audit'], $config->validate('internal', 'TerminalRegistered', ['handler' => 'audit']));
        self::assertSame(['name' => 'attendance'], $config->validate('queue', 'TerminalSuspended', ['name' => 'attendance']));
    }

    public function testNonTextConfigurationIsRejected(): void
    {
        $config = new IntegrationConfig();

        try {
            $config->validate('webhook', 'EmployeeClockedIn', ['url' => ['https://hooks.example.com/attendance']]);
            self::fail('A nested value must be rejected.');
        } catch (InvalidTenantInputException) {
        }
        $this->expectException(InvalidTenantInputException::class);
        $config->validate('webhook', 'EmployeeClockedIn', ['https://hooks.example.com/attendance']);
    }

    public function testExecutableConfigurationIsRejected(): void
    {
        $config = new IntegrationConfig();

        $this->expectException(InvalidTenantInputException::class);
        $config->validate('internal', 'EmployeeClockedIn', ['class' => 'App\\Evil']);
    }

    public function testPhpSourceIsRejected(): void
    {
        $config = new IntegrationConfig();

        $this->expectException(InvalidTenantInputException::class);
        $config->validate('webhook', 'EmployeeClockedIn', ['url' => 'https://hooks.example.com/<?php']);
    }

    public function testPrivateAndLocalWebhooksAreRejected(): void
    {
        $config = new IntegrationConfig();
        $rejected = 0;
        foreach ([
            'http://hooks.example.com/attendance',
            'https://localhost/attendance',
            'https://127.0.0.1/attendance',
            'https://10.1.1.1/attendance',
            'https://hooks.example.internal/attendance',
            'https://user:secret@hooks.example.com/attendance',
        ] as $url) {
            try {
                $config->validate('webhook', 'EmployeeClockedIn', ['url' => $url]);
                self::fail($url);
            } catch (InvalidTenantInputException) {
                ++$rejected;
            }
        }
        self::assertSame(6, $rejected);
    }

    public function testUnknownEventsAndKindsAreRejected(): void
    {
        $config = new IntegrationConfig();

        try {
            $config->validate('plugin', 'EmployeeClockedIn', []);
            self::fail('An unknown kind must be rejected.');
        } catch (InvalidTenantInputException) {
        }
        $this->expectException(InvalidTenantInputException::class);
        $config->validate('internal', 'TenantUploadedCode', ['handler' => 'audit']);
    }
}

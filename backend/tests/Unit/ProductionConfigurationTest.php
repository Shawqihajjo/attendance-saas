<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Diagnostics\ProductionConfiguration;
use App\Diagnostics\ProductionConfigurationException;
use App\Kernel;
use PHPUnit\Framework\TestCase;

final class ProductionConfigurationTest extends TestCase
{
    public function testDevelopmentAndTestBootsAreLeftAlone(): void
    {
        ProductionConfiguration::assertSafe('dev', true);
        ProductionConfiguration::assertSafe('test', true);

        self::assertTrue((new Kernel('dev', true))->isDebug());
        self::assertSame('test', (new Kernel('test', true))->getEnvironment());
    }

    public function testProductionRefusesDebugAndTheLocalSecrets(): void
    {
        $this->expectException(ProductionConfigurationException::class);
        $this->expectExceptionMessage('debugging');
        ProductionConfiguration::assertSafe('prod', true);
    }

    public function testProductionRefusesTheCheckedInDevelopmentSecret(): void
    {
        $previous = $this->replace([
            'APP_DEBUG' => '0',
            'APP_SECRET' => 'dev-only-change-me',
        ]);

        try {
            $this->expectException(ProductionConfigurationException::class);
            $this->expectExceptionMessage('APP_SECRET');
            ProductionConfiguration::assertSafe('prod', false);
        } finally {
            $this->restore($previous);
        }
    }

    public function testProductionAcceptsEnvironmentSuppliedSecrets(): void
    {
        $previous = $this->replace($this->productionEnvironment());

        try {
            ProductionConfiguration::assertSafe('prod', false);
            $kernel = new Kernel('prod', false);
            self::assertSame('prod', $kernel->getEnvironment());
            self::assertFalse($kernel->isDebug());
        } finally {
            $this->restore($previous);
        }
    }

    /**
     * @param array<string, string> $values
     *
     * @return array<string, string|false>
     */
    private function replace(array $values): array
    {
        $previous = [];
        foreach ($values as $name => $value) {
            $current = $_SERVER[$name] ?? false;
            $previous[$name] = is_string($current) ? $current : false;
            $_SERVER[$name] = $value;
            $_ENV[$name] = $value;
        }

        return $previous;
    }

    /**
     * @param array<string, string|false> $previous
     */
    private function restore(array $previous): void
    {
        foreach ($previous as $name => $value) {
            if ($value === false) {
                unset($_SERVER[$name], $_ENV[$name]);
                continue;
            }
            $_SERVER[$name] = $value;
            $_ENV[$name] = $value;
        }
    }

    /**
     * @return array<string, string>
     */
    private function productionEnvironment(): array
    {
        return [
            'APP_DEBUG' => '0',
            'APP_SECRET' => 'prod-config-test-secret-0123456789abcd',
            'TENANT_DB_CREDENTIALS_KEY' => base64_encode(str_repeat('a', 32)),
            'TENANT_DB_CREDENTIALS_KEY_ID' => 'primary',
            'TENANT_DATABASE_DRIVER' => 'mysql',
            'PLATFORM_DATABASE_URL' => 'mysql://app:unique-platform-password@mysql:3306/platform?serverVersion=8.4&charset=utf8mb4',
            'TENANT_DATABASE_URL' => 'mysql://app:unique-platform-password@mysql:3306/tenant_placeholder?serverVersion=8.4&charset=utf8mb4',
            'TENANT_DATABASE_ADMIN_URL' => 'mysql://root:unique-admin-password@mysql:3306',
            'MESSENGER_TRANSPORT_DSN' => 'amqp://queue:unique-queue-password@rabbitmq:5672/%2f',
            'REDIS_URL' => 'redis://:unique-redis-password@redis:6379',
            'TRUSTED_PROXIES' => 'REMOTE_ADDR',
            'DEFAULT_URI' => 'https://attendance.example',
            'PLATFORM_HOSTNAME' => 'attendance.example',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Diagnostics;

/**
 * Refuses a production boot that still carries the local development configuration.
 * The exception text names the variable and never the value.
 */
final class ProductionConfiguration
{
    /** @var array<string, true> */
    private const DEVELOPMENT_SHA256 = [
        '594f16f52d089b125f79a544eeda4b2c6a0dfbb8b1cae3e09e2176ebeb27997c' => true,
        '7c9228398918ae3aa0ad130bf3307adc0a87079afef56e7ccbde94f96871ff64' => true,
        '8670a8fdabb073efe0b1901f40a07a1a219664a251ec830f07b52ec76bf15c0d' => true,
        'f7c50cb9b76479bdfb9daddef7221503d5c2e42530a8c6d819747c8208afe22b' => true,
        'd4d06acf8a0a4510d04279860827a0babd93d2c12630665eb0991ff98bcf4641' => true,
        'f46e6ae0768a564008dd14b0c1480b5cc5ff64db0507bf8018c3d58ee863a680' => true,
        '5a3bcbcef5b4e4d069cf81db423a5de624c6d0bc32c227cd32d4bc9ee9145ec1' => true,
    ];

    /** @var list<string> */
    private const DEVELOPMENT_MARKERS = [
        'rootsecret',
        'dev-only-change-me',
        'dev-only-not-a-production-secret',
        'test_secret_not_for_production',
        'bG9jYWwtZGV2LXRlbmFudC1kYi1rZXktMzJieXRlcyE=',
        ':attendance@',
    ];

    public static function assertSafe(string $environment, bool $debug): void
    {
        if ($environment !== 'prod') {
            return;
        }

        $debugFlag = strtolower(self::read('APP_DEBUG'));
        if ($debug || $debugFlag === '1' || $debugFlag === 'true') {
            throw new ProductionConfigurationException('Production cannot run with debugging enabled.');
        }

        $secret = self::read('APP_SECRET');
        if (strlen($secret) < 32 || self::isDevelopmentValue($secret)) {
            throw new ProductionConfigurationException('APP_SECRET is missing or still a development value.');
        }

        $key = self::read('TENANT_DB_CREDENTIALS_KEY');
        $decoded = base64_decode($key, true);
        if (!is_string($decoded) || strlen($decoded) !== 32 || self::isDevelopmentValue($key)) {
            throw new ProductionConfigurationException('TENANT_DB_CREDENTIALS_KEY is missing or still a development value.');
        }

        $keyId = self::read('TENANT_DB_CREDENTIALS_KEY_ID');
        if ($keyId === '' || $keyId === 'dev' || $keyId === 'test') {
            throw new ProductionConfigurationException('TENANT_DB_CREDENTIALS_KEY_ID must name the production key.');
        }

        if (self::read('TENANT_DATABASE_DRIVER') !== 'mysql') {
            throw new ProductionConfigurationException('TENANT_DATABASE_DRIVER must be mysql in production.');
        }

        self::assertMysqlUrl('PLATFORM_DATABASE_URL');
        self::assertMysqlUrl('TENANT_DATABASE_URL');
        self::assertMysqlUrl('TENANT_DATABASE_ADMIN_URL');

        $messenger = self::read('MESSENGER_TRANSPORT_DSN');
        if (!str_starts_with($messenger, 'amqp://') && !str_starts_with($messenger, 'amqps://')) {
            throw new ProductionConfigurationException('Messenger must use AMQP in production.');
        }
        self::rejectMarkers('MESSENGER_TRANSPORT_DSN', $messenger);

        try {
            $redis = RedisEndpoint::fromUrl(self::read('REDIS_URL'));
        } catch (\InvalidArgumentException) {
            $redis = null;
        }
        if (!$redis instanceof RedisEndpoint || $redis->password === null || self::isDevelopmentValue($redis->password)) {
            throw new ProductionConfigurationException('Redis must require a password in production.');
        }

        if (self::read('TRUSTED_PROXIES') === '') {
            throw new ProductionConfigurationException('TRUSTED_PROXIES must be set in production.');
        }
        if (!str_starts_with(self::read('DEFAULT_URI'), 'https://')) {
            throw new ProductionConfigurationException('DEFAULT_URI must use https in production.');
        }
        if (self::read('PLATFORM_HOSTNAME') === '') {
            throw new ProductionConfigurationException('PLATFORM_HOSTNAME must be set in production.');
        }
    }

    private static function assertMysqlUrl(string $name): void
    {
        $value = self::read($name);
        if (!str_starts_with($value, 'mysql:')) {
            throw new ProductionConfigurationException($name.' must be a MySQL URL supplied by the environment.');
        }
        self::rejectMarkers($name, $value);
    }

    private static function rejectMarkers(string $name, string $value): void
    {
        foreach (self::DEVELOPMENT_MARKERS as $marker) {
            if (str_contains($value, $marker)) {
                throw new ProductionConfigurationException($name.' still contains a development credential.');
            }
        }
    }

    private static function isDevelopmentValue(string $value): bool
    {
        return $value === '' || isset(self::DEVELOPMENT_SHA256[hash('sha256', $value)]);
    }

    private static function read(string $name): string
    {
        if (isset($_SERVER[$name]) && is_string($_SERVER[$name])) {
            return $_SERVER[$name];
        }
        if (isset($_ENV[$name]) && is_string($_ENV[$name])) {
            return $_ENV[$name];
        }
        $value = getenv($name);

        return is_string($value) ? $value : '';
    }
}

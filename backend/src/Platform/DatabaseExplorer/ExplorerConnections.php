<?php

declare(strict_types=1);

namespace App\Platform\DatabaseExplorer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Opens the explorer account only. An empty URL or a copy of the application MySQL account is refused.
 */
final class ExplorerConnections
{
    public function __construct(
        #[Autowire('%env(resolve:EXPLORER_PLATFORM_DATABASE_URL)%')]
        private readonly string $platformUrl,
        #[Autowire('%env(resolve:EXPLORER_TENANT_DATABASE_URL)%')]
        private readonly string $tenantUrl,
        #[Autowire('%env(resolve:PLATFORM_DATABASE_URL)%')]
        private readonly string $applicationPlatformUrl,
        #[Autowire('%env(resolve:TENANT_DATABASE_URL)%')]
        private readonly string $applicationTenantUrl,
        #[Autowire('%app.tenant_database_server_version%')]
        private readonly string $serverVersion,
    ) {
    }

    public function assertConfigured(): void
    {
        $this->guard($this->platformUrl, $this->applicationPlatformUrl);
        $this->guard($this->tenantUrl, $this->applicationTenantUrl);
    }

    public function openPlatform(): Connection
    {
        $this->guard($this->platformUrl, $this->applicationPlatformUrl);

        return $this->connect($this->paramsFromUrl($this->platformUrl), null);
    }

    public function openTenant(string $databaseName, string $registryHost, string $driver): Connection
    {
        $this->guard($this->tenantUrl, $this->applicationTenantUrl);
        if (!ExplorerPolicy::identifier($databaseName) || !preg_match('/^tenant_[a-f0-9]{32}$/', $databaseName)) {
            throw new ExplorerUnavailableException('The read-only database explorer could not open the selected database.');
        }
        $params = $this->paramsFromUrl($this->tenantUrl);
        $scheme = (string) ($params['driver'] ?? '');
        if ($driver === 'sqlite') {
            if ($scheme !== 'pdo_sqlite') {
                throw new ExplorerUnavailableException('The read-only database explorer could not open the selected database.');
            }
            $directory = $this->sqliteLocation((string) ($params['path'] ?? ''));
            $root = realpath($directory);
            if ($root === false || !is_dir($root)) {
                throw new ExplorerUnavailableException('The read-only database explorer could not open the selected database.');
            }
            $path = $root.DIRECTORY_SEPARATOR.$databaseName.'.sqlite';
            if (!is_file($path)) {
                throw new ExplorerTargetMissingException();
            }
            $params['path'] = $path;

            return $this->connect($params, null);
        }
        if ($driver !== 'mysql' || $scheme !== 'pdo_mysql') {
            throw new ExplorerUnavailableException('The read-only database explorer could not open the selected database.');
        }
        $host = strtolower((string) ($params['host'] ?? ''));
        if ($host === '' || strtolower($registryHost) !== $host) {
            throw new ExplorerUnavailableException('The read-only database explorer could not open the selected database.');
        }
        $params['dbname'] = $databaseName;

        return $this->connect($params, $databaseName);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function connect(array $params, ?string $databaseName): Connection
    {
        if ($databaseName !== null) {
            $params['dbname'] = $databaseName;
        }
        if (($params['driver'] ?? '') === 'pdo_sqlite') {
            $params['path'] = $this->sqliteLocation((string) ($params['path'] ?? ''));
            unset($params['host']);
        }
        try {
            $connection = DriverManager::getConnection($params);
            $connection->executeQuery('SELECT 1');
            if ($connection->getDatabasePlatform() instanceof SQLitePlatform) {
                $connection->executeStatement('PRAGMA query_only = ON');
            } else {
                $connection->executeStatement('SET SESSION TRANSACTION READ ONLY');
                $connection->executeStatement('SET SESSION MAX_EXECUTION_TIME = 5000');
            }
        } catch (ExplorerUnavailableException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new ExplorerUnavailableException('The read-only database explorer could not open the selected database.');
        }

        return $connection;
    }

    /**
     * @return array<string, mixed>
     */
    private function paramsFromUrl(string $url): array
    {
        try {
            $params = (new DsnParser(['sqlite' => 'pdo_sqlite', 'mysql' => 'pdo_mysql']))->parse($url);
        } catch (\Throwable) {
            throw new ExplorerUnavailableException('The read-only database explorer account is not configured.');
        }
        $params['driverOptions'] = [\PDO::ATTR_PERSISTENT => false];
        if (($params['driver'] ?? '') === 'pdo_mysql') {
            $params['charset'] = 'utf8mb4';
            $params['serverVersion'] = $this->serverVersion;
            if (defined('PDO::MYSQL_ATTR_MULTI_STATEMENTS')) {
                $params['driverOptions'][\PDO::MYSQL_ATTR_MULTI_STATEMENTS] = false;
            }
        }

        return $params;
    }

    private function guard(string $explorer, string $application): void
    {
        if (trim($explorer) === '') {
            throw new ExplorerUnavailableException();
        }
        if ($this->repeatsApplicationAccount($explorer, $application)) {
            throw new ExplorerUnavailableException();
        }
    }

    private function repeatsApplicationAccount(string $explorer, string $application): bool
    {
        $left = parse_url($explorer);
        $right = parse_url($application);
        if (!is_array($left) || !is_array($right)) {
            return false;
        }
        if (($left['scheme'] ?? '') === 'sqlite' || ($right['scheme'] ?? '') === 'sqlite') {
            return false;
        }
        $user = rawurldecode((string) ($left['user'] ?? ''));
        $other = rawurldecode((string) ($right['user'] ?? ''));
        $password = rawurldecode((string) ($left['pass'] ?? ''));
        $otherPassword = rawurldecode((string) ($right['pass'] ?? ''));

        return $user !== '' && $user === $other && $password === $otherPassword;
    }

    private function sqliteLocation(string $path): string
    {
        if ($path !== '' && !str_starts_with($path, '/') && (is_file('/'.$path) || is_dir('/'.$path))) {
            return '/'.$path;
        }

        return $path;
    }
}

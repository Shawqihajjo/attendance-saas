<?php

declare(strict_types=1);

namespace App\TenantContext\Infrastructure\Doctrine;

use App\Platform\TenantRegistry\Infrastructure\Security\SecretMemory;
use App\TenantContext\Domain\TenantDatabaseNotSelectedException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Connection as DriverConnection;

final class TenantAwareConnection extends Connection
{
    private bool $selected = false;

    private bool $utcApplied = false;

    public function isTenantSelected(): bool
    {
        return $this->selected;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function selectDatabase(array $params): void
    {
        if ($this->isConnected()) {
            $this->close();
        }
        $this->utcApplied = false;
        $this->clearCachedPlatform();
        $current = $this->getParams();
        foreach (['url', 'host', 'port', 'dbname', 'user', 'password', 'path', 'memory', 'unix_socket'] as $key) {
            unset($current[$key]);
        }
        $this->writeParams(array_merge($current, $params));
        $this->selected = true;
    }

    public function release(): void
    {
        if ($this->isConnected()) {
            $this->close();
        }
        $params = $this->getParams();
        $password = isset($params['password']) && is_string($params['password']) ? $params['password'] : '';
        SecretMemory::forget($password);
        $params['password'] = '';
        $this->writeParams($params);
        $this->utcApplied = false;
        $this->clearCachedPlatform();
        $this->selected = false;
    }

    protected function connect(): DriverConnection
    {
        if (!$this->selected) {
            throw new TenantDatabaseNotSelectedException();
        }

        $connection = parent::connect();
        if (!$this->utcApplied && ($this->getParams()['driver'] ?? null) === 'pdo_mysql') {
            $connection->exec("SET time_zone = '+00:00'");
            $this->utcApplied = true;
        }

        return $connection;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function writeParams(array $params): void
    {
        $property = new \ReflectionProperty(Connection::class, 'params');
        $property->setValue($this, $params);
    }

    private function clearCachedPlatform(): void
    {
        $property = new \ReflectionProperty(Connection::class, 'platform');
        $property->setValue($this, null);
    }
}

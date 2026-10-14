<?php

declare(strict_types=1);

namespace App\TenantContext\Infrastructure\Doctrine;

use App\Platform\TenantRegistry\Domain\InvalidTenantInputException;
use App\Platform\TenantRegistry\Domain\TenantInputValidator;
use App\Platform\TenantRegistry\Domain\TenantStatus;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantDatabaseConnection;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantSetting;
use App\Platform\TenantRegistry\Infrastructure\Security\SecretMemory;
use App\Platform\TenantRegistry\Infrastructure\Security\SodiumCredentialCipher;
use App\TenantContext\Domain\TenantConnectionProfile;
use App\TenantContext\Domain\TenantConnectionPurpose;
use App\TenantContext\Domain\TenantDatabaseParametersProvider;
use App\TenantContext\Domain\TenantNotProvisionedException;
use App\TenantContext\Domain\TenantUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

final class RegistryTenantDatabaseParametersProvider implements TenantDatabaseParametersProvider
{
    public function __construct(
        private readonly TenantRegistryStore $store,
        private readonly TenantInputValidator $validator,
        private readonly SodiumCredentialCipher $cipher,
        #[Autowire('%app.tenant_database_directory%')]
        private readonly string $databaseDirectory,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire('%app.tenant_database_server_version%')]
        private readonly string $serverVersion,
    ) {
    }

    public function getProfile(string $tenantId, TenantConnectionPurpose $purpose): TenantConnectionProfile
    {
        if (!Uuid::isValid($tenantId)) {
            throw new TenantNotProvisionedException($tenantId);
        }
        $tenant = $this->store->findTenant($tenantId);
        if ($tenant === null) {
            throw new TenantNotProvisionedException($tenantId);
        }
        if ($purpose === TenantConnectionPurpose::Runtime && $tenant->status() !== TenantStatus::Active) {
            throw new TenantUnavailableException($tenant->status()->value);
        }
        if ($purpose === TenantConnectionPurpose::Maintenance && $tenant->status() === TenantStatus::Decommissioned) {
            throw new TenantUnavailableException($tenant->status()->value);
        }

        $connection = $this->store->findConnection($tenantId);
        $settings = $this->store->findSettings($tenantId);
        if (!$connection instanceof TenantDatabaseConnection || $connection->credentialsState() !== 'present' || !$settings instanceof TenantSetting) {
            throw new TenantNotProvisionedException($tenantId);
        }

        $enabledLocales = [];
        $defaultLocale = null;
        foreach ($this->store->locales($tenantId) as $locale) {
            $enabledLocales[] = $locale->locale();
            if ($locale->isDefault()) {
                $defaultLocale = $locale->locale();
            }
        }
        if ($defaultLocale === null) {
            throw new TenantNotProvisionedException($tenantId);
        }

        return new TenantConnectionProfile(
            $tenant->id(),
            $tenant->slug(),
            $settings->timezone(),
            $defaultLocale,
            $enabledLocales,
            $settings->photoRequired(),
            $this->connectionParams($connection),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionParams(TenantDatabaseConnection $connection): array
    {
        try {
            $databaseName = $this->validator->databaseName($connection->databaseName());
            $host = $this->validator->databaseHost($connection->host());
        } catch (InvalidTenantInputException) {
            throw new TenantNotProvisionedException($connection->tenantId());
        }
        if ($connection->port() < 1 || $connection->port() > 65535) {
            throw new TenantNotProvisionedException($connection->tenantId());
        }
        if ($connection->keyId() !== $this->cipher->keyId()) {
            throw new TenantNotProvisionedException($connection->tenantId());
        }

        $password = $this->cipher->decrypt($connection->passwordCiphertext());
        try {
            if ($connection->driver() === 'sqlite') {
                if ($this->environment === 'prod') {
                    throw new \LogicException('SQLite tenant databases are not available in production.');
                }

                return [
                    'driver' => 'pdo_sqlite',
                    'path' => $this->sqlitePath($connection->tenantId(), $databaseName),
                ];
            }
            if ($connection->driver() !== 'mysql') {
                throw new TenantNotProvisionedException($connection->tenantId());
            }

            return [
                'driver' => 'pdo_mysql',
                'host' => $host,
                'port' => $connection->port(),
                'dbname' => $databaseName,
                'user' => $this->validator->databaseUser($connection->username()),
                'password' => $password,
                'charset' => 'utf8mb4',
                'serverVersion' => $this->serverVersion,
            ];
        } catch (InvalidTenantInputException) {
            throw new TenantNotProvisionedException($connection->tenantId());
        } finally {
            if ($connection->driver() !== 'mysql') {
                SecretMemory::forget($password);
            }
        }
    }

    private function sqlitePath(string $tenantId, string $databaseName): string
    {
        $root = realpath($this->databaseDirectory);
        if ($root === false) {
            throw new TenantNotProvisionedException($tenantId);
        }

        return $root.DIRECTORY_SEPARATOR.$databaseName.'.sqlite';
    }
}

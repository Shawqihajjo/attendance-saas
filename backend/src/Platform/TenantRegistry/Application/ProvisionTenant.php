<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Application;

use App\Platform\TenantRegistry\Domain\InvalidTenantInputException;
use App\Platform\TenantRegistry\Domain\ProvisionTenantRequest;
use App\Platform\TenantRegistry\Domain\TenantDatabaseNames;
use App\Platform\TenantRegistry\Domain\TenantDatabaseProvisioner;
use App\Platform\TenantRegistry\Domain\TenantDatabaseSpec;
use App\Platform\TenantRegistry\Domain\TenantInputValidator;
use App\Platform\TenantRegistry\Domain\TenantProvisioningException;
use App\Platform\TenantRegistry\Infrastructure\Migrations\DoctrineMigrationRunner;
use App\Platform\TenantRegistry\Infrastructure\Persistence\Tenant;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantBranding;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantDatabaseConnection;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantDomain;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantLocale;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantSetting;
use App\Platform\TenantRegistry\Infrastructure\Security\SecretMemory;
use App\Platform\TenantRegistry\Infrastructure\Security\SodiumCredentialCipher;
use App\Shared\Clock\UtcClock;
use App\TenantContext\Domain\TenantDatabaseSwitcher;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

final class ProvisionTenant
{
    public function __construct(
        private readonly TenantRegistryStore $store,
        private readonly TenantInputValidator $validator,
        private readonly SodiumCredentialCipher $cipher,
        private readonly TenantDatabaseProvisioner $provisioner,
        private readonly TenantDatabaseSwitcher $switcher,
        private readonly DoctrineMigrationRunner $migrations,
        private readonly PlatformAuditRecorder $audit,
        private readonly UtcClock $clock,
        #[Autowire('%app.platform_hostname%')]
        private readonly string $platformHost,
        #[Autowire('%app.tenant_database_driver%')]
        private readonly string $driver,
        #[Autowire('%app.tenant_database_host%')]
        private readonly string $databaseHost,
        #[Autowire('%app.tenant_database_port%')]
        private readonly int $databasePort,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public function execute(ProvisionTenantRequest $request): Tenant
    {
        $slug = $this->validator->slug($request->slug);
        $legalName = $this->validator->legalName($request->legalName);
        $hostname = $this->validator->hostname($request->hostname, $this->platformHost);
        $timezone = $this->validator->timezone($request->timezone);
        $locale = $this->validator->locale($request->defaultLocale);
        $enabledLocales = $this->enabledLocales($request->enabledLocales, $locale);
        $actorId = $this->validator->actorId($request->actorId);
        if ($this->driver !== 'mysql' && $this->driver !== 'sqlite') {
            throw new \LogicException('TENANT_DATABASE_DRIVER must be mysql or sqlite.');
        }
        if ($this->driver === 'sqlite' && $this->environment === 'prod') {
            throw new \LogicException('SQLite tenant databases are not available in production.');
        }
        $this->validator->databaseHost($this->databaseHost);
        if ($this->store->findTenantBySlug($slug) instanceof Tenant || $this->store->findDomain($hostname) instanceof TenantDomain) {
            throw new TenantProvisioningException('The tenant slug or hostname is already registered.');
        }

        $now = $this->clock->now();
        $id = Uuid::v7()->toRfc4122();
        $password = TenantDatabaseNames::password();
        $spec = new TenantDatabaseSpec(TenantDatabaseNames::databaseName(), TenantDatabaseNames::username(), $password);
        $tenant = new Tenant($id, $slug, $legalName, $now);
        $this->store->persist($tenant);
        $this->store->persist(new TenantDomain(Uuid::v7()->toRfc4122(), $id, $hostname, true, $now));
        $this->store->persist(new TenantDatabaseConnection(
            $id,
            $this->driver,
            $this->databaseHost,
            $this->databasePort,
            $spec->databaseName,
            $spec->username,
            $this->cipher->encrypt($password),
            $this->cipher->keyId(),
        ));
        $this->store->persist(new TenantBranding($id, $legalName));
        foreach ($enabledLocales as $code) {
            $this->store->persist(new TenantLocale(Uuid::v7()->toRfc4122(), $id, $code, $code === $locale));
        }
        $this->store->persist(new TenantSetting($id, $timezone, true));
        $this->store->flush();

        try {
            $this->openAndMigrate($spec, $id);
            $tenant->markActive($this->clock->now());
            $this->audit->record('tenant.provisioned', 'tenant', $id, $actorId, [
                'slug' => $slug,
                'hostname' => $hostname,
                'database' => $spec->databaseName,
                'status' => 'active',
            ]);
            $this->store->flush();
        } catch (\Throwable $exception) {
            $failure = $exception instanceof TenantProvisioningException
                ? $exception
                : new TenantProvisioningException('The tenant database could not be provisioned.', 0, $exception);
            $this->recordFailure($id, $actorId, $slug, $failure);
            throw $failure;
        } finally {
            SecretMemory::forget($password);
            $this->switcher->reset();
        }

        return $tenant;
    }

    private function openAndMigrate(TenantDatabaseSpec $spec, string $tenantId): void
    {
        $this->provisioner->provision($spec);
        $this->switcher->switchForMaintenance($tenantId);
        $this->migrations->migrateTenant();
    }

    /**
     * @param list<string> $requested
     *
     * @return list<string>
     */
    private function enabledLocales(array $requested, string $default): array
    {
        if ($requested === []) {
            return [$default, $default === 'en' ? 'sv' : 'en'];
        }
        $codes = [];
        foreach ($requested as $locale) {
            $codes[$this->validator->locale($locale)] = true;
        }
        if (!isset($codes[$default])) {
            throw new InvalidTenantInputException('The default locale must be one of the enabled locales.');
        }

        return array_keys($codes);
    }

    private function recordFailure(string $tenantId, string $actorId, string $slug, \Throwable $exception): void
    {
        try {
            $this->audit->record('tenant.provision_failed', 'tenant', $tenantId, $actorId, [
                'slug' => $slug,
                'result' => 'failure',
                'reason' => SafeFailure::reason($exception),
            ]);
        } catch (\Throwable) {
        }
    }
}

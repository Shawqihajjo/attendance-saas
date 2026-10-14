<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Application;

use App\Platform\TenantRegistry\Domain\TenantDatabaseProvisioner;
use App\Platform\TenantRegistry\Domain\TenantDatabaseSpec;
use App\Platform\TenantRegistry\Domain\TenantInputValidator;
use App\Platform\TenantRegistry\Domain\TenantProvisioningException;
use App\Platform\TenantRegistry\Domain\TenantStatus;
use App\Platform\TenantRegistry\Infrastructure\Migrations\DoctrineMigrationRunner;
use App\Platform\TenantRegistry\Infrastructure\Persistence\Tenant;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantDatabaseConnection;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\Platform\TenantRegistry\Infrastructure\Security\SecretMemory;
use App\Platform\TenantRegistry\Infrastructure\Security\SodiumCredentialCipher;
use App\Shared\Clock\UtcClock;
use App\TenantContext\Domain\TenantDatabaseSwitcher;
use App\TenantContext\Domain\TenantNotProvisionedException;

final class MigrateTenantDatabases
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
    ) {
    }

    public function migrateSlug(string $slug, string $actorId): void
    {
        $tenant = $this->store->findTenantBySlug($this->validator->slug($slug));
        if (!$tenant instanceof Tenant) {
            throw new TenantNotProvisionedException($slug);
        }
        $this->migrateOne($tenant, $this->validator->actorId($actorId));
    }

    public function migrateAll(string $actorId): int
    {
        $actorId = $this->validator->actorId($actorId);
        $count = 0;
        foreach ($this->store->tenantsForMaintenance() as $tenant) {
            $this->migrateOne($tenant, $actorId);
            ++$count;
        }

        return $count;
    }

    private function migrateOne(Tenant $tenant, string $actorId): void
    {
        $connection = $this->store->findConnection($tenant->id());
        if (!$connection instanceof TenantDatabaseConnection || $connection->credentialsState() !== 'present') {
            $this->recordFailure($tenant, $actorId, 'The tenant database is not provisioned.');
            throw new TenantNotProvisionedException($tenant->id());
        }
        $password = '';
        try {
            $password = $this->cipher->decrypt($connection->passwordCiphertext());
            $this->provisioner->provision(new TenantDatabaseSpec(
                $connection->databaseName(),
                $connection->username(),
                $password,
            ));
            $this->switcher->switchForMaintenance($tenant->id());
            $this->migrations->migrateTenant();
            if ($tenant->status() === TenantStatus::Provisioning) {
                $tenant->markActive($this->clock->now());
                $this->audit->record('tenant.activated', 'tenant', $tenant->id(), $actorId, [
                    'slug' => $tenant->slug(),
                    'status' => 'active',
                ]);
                $this->store->flush();
            }
        } catch (\Throwable $exception) {
            $failure = $exception instanceof TenantProvisioningException
                ? $exception
                : new TenantProvisioningException('The tenant database could not be migrated.', 0, $exception);
            $this->recordFailure($tenant, $actorId, SafeFailure::reason($failure));
            throw $failure;
        } finally {
            SecretMemory::forget($password);
            $this->switcher->reset();
        }
    }

    private function recordFailure(Tenant $tenant, string $actorId, string $reason): void
    {
        try {
            $this->audit->record('tenant.migration_failed', 'tenant', $tenant->id(), $actorId, [
                'slug' => $tenant->slug(),
                'result' => 'failure',
                'reason' => $reason,
            ]);
        } catch (\Throwable) {
        }
    }
}

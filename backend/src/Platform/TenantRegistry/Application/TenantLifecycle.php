<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Application;

use App\Platform\TenantRegistry\Domain\TenantDatabaseProvisioner;
use App\Platform\TenantRegistry\Domain\TenantInputValidator;
use App\Platform\TenantRegistry\Domain\TenantLifecycleException;
use App\Platform\TenantRegistry\Domain\TenantStatus;
use App\Platform\TenantRegistry\Infrastructure\Persistence\Tenant;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantDatabaseConnection;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\Shared\Clock\UtcClock;
use App\TenantContext\Domain\TenantNotProvisionedException;

final class TenantLifecycle
{
    public function __construct(
        private readonly TenantRegistryStore $store,
        private readonly TenantInputValidator $validator,
        private readonly TenantDatabaseProvisioner $provisioner,
        private readonly PlatformAuditRecorder $audit,
        private readonly UtcClock $clock,
    ) {
    }

    public function suspend(string $slug, string $actorId): Tenant
    {
        $tenant = $this->require($slug);
        $tenant->suspend($this->clock->now());
        $this->finish('tenant.suspended', $tenant, $actorId);

        return $tenant;
    }

    public function reactivate(string $slug, string $actorId): Tenant
    {
        $tenant = $this->require($slug);
        $tenant->reactivate($this->clock->now());
        $this->finish('tenant.reactivated', $tenant, $actorId);

        return $tenant;
    }

    public function decommission(string $slug, string $actorId): Tenant
    {
        $tenant = $this->require($slug);
        $tenant->decommission($this->clock->now());
        $this->finish('tenant.decommissioned', $tenant, $actorId);

        return $tenant;
    }

    public function dropDatabase(string $slug, string $actorId): void
    {
        $tenant = $this->require($slug);
        $actorId = $this->validator->actorId($actorId);
        if ($tenant->status() !== TenantStatus::Decommissioned) {
            throw new TenantLifecycleException('Only a decommissioned tenant database can be dropped.');
        }
        $connection = $this->store->findConnection($tenant->id());
        if (!$connection instanceof TenantDatabaseConnection) {
            throw new TenantNotProvisionedException($tenant->id());
        }
        if ($connection->credentialsState() === 'dropped') {
            return;
        }
        $databaseName = $connection->databaseName();
        $this->provisioner->drop($databaseName, $connection->username());
        $connection->markDropped($this->clock->now());
        $this->audit->record('tenant.database_dropped', 'tenant', $tenant->id(), $actorId, [
            'slug' => $tenant->slug(),
            'database' => $databaseName,
            'status' => $tenant->status()->value,
        ]);
        $this->store->flush();
    }

    private function require(string $slug): Tenant
    {
        $tenant = $this->store->findTenantBySlug($this->validator->slug($slug));
        if (!$tenant instanceof Tenant) {
            throw new TenantNotProvisionedException($slug);
        }

        return $tenant;
    }

    private function finish(string $action, Tenant $tenant, string $actorId): void
    {
        $this->audit->record($action, 'tenant', $tenant->id(), $this->validator->actorId($actorId), [
            'slug' => $tenant->slug(),
            'status' => $tenant->status()->value,
        ]);
        $this->store->flush();
    }
}

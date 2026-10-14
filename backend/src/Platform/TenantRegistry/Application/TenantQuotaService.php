<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Application;

use App\Platform\TenantRegistry\Domain\DeviceStatus;
use App\Platform\TenantRegistry\Domain\InvalidTenantInputException;
use App\Platform\TenantRegistry\Domain\QuotaUsage;
use App\Platform\TenantRegistry\Domain\TenantQuotaExceededException;
use App\Platform\TenantRegistry\Domain\TenantStatus;
use App\Platform\TenantRegistry\Infrastructure\Persistence\DeviceRegistration;
use App\Platform\TenantRegistry\Infrastructure\Persistence\Tenant;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantQuota;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\TenantContext\Domain\TenantContextHolder;
use App\TenantContext\Domain\TenantDatabaseSwitcher;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Platform limits are stored in tenant_quotas. null is unlimited and 0 allows no further increase.
 *
 * Active users and active employees are counted in the tenant database. Active devices are counted
 * from device_registry. Pending, registered, suspended, and revoked devices are not active.
 */
final class TenantQuotaService
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly TenantRegistryStore $store,
        private readonly TenantDatabaseSwitcher $switcher,
        private readonly TenantContextHolder $context,
        private readonly PlatformAuditRecorder $audit,
        #[Autowire(service: 'doctrine.dbal.tenant_connection')]
        private readonly \Doctrine\DBAL\Connection $tenantConnection,
    ) {
    }

    /**
     * @param array{maxUsers: ?int, maxEmployees: ?int, maxActiveDevices: ?int} $limits
     *
     * @return array<string, mixed>
     */
    public function replace(Tenant $tenant, array $limits, string $actorId): array
    {
        $previous = $this->storedLimits($tenant->id());
        $quota = $this->platform()->find(TenantQuota::class, $tenant->id());
        if (!$quota instanceof TenantQuota) {
            $quota = new TenantQuota($tenant->id());
            $this->store->persist($quota);
        }
        $quota->replace($limits['maxUsers'], $limits['maxEmployees'], $limits['maxActiveDevices']);
        $this->audit->record('tenant.quotas_updated', 'tenant', $tenant->id(), $actorId, [
            'slug' => $tenant->slug(),
            'maxUsers' => $limits['maxUsers'],
            'maxEmployees' => $limits['maxEmployees'],
            'maxActiveDevices' => $limits['maxActiveDevices'],
            'previousMaxUsers' => $previous['maxUsers'],
            'previousMaxEmployees' => $previous['maxEmployees'],
            'previousMaxActiveDevices' => $previous['maxActiveDevices'],
            'result' => 'success',
        ]);
        $this->store->flush();

        return $this->platformReport($tenant);
    }

    /**
     * @return array<string, mixed>
     */
    public function platformReport(Tenant $tenant): array
    {
        $limits = $this->storedLimits($tenant->id());
        $devices = $this->countActiveDevices($tenant->id());
        $users = null;
        $employees = null;
        if ($tenant->status() !== TenantStatus::Decommissioned) {
            try {
                $this->switcher->switchForMaintenance($tenant->id());
                $users = $this->countUsers();
                $employees = $this->countEmployees();
            } catch (\Throwable) {
                $users = null;
                $employees = null;
            } finally {
                $this->switcher->reset();
            }
        }

        return $this->report($limits, $users, $employees, $devices);
    }

    /**
     * @return array<string, mixed>
     */
    public function currentTenantReport(): array
    {
        $current = $this->context->current();
        if ($current === null) {
            throw new InvalidTenantInputException('The tenant context is missing.');
        }
        $limits = $this->storedLimits($current->tenantId);

        return $this->report(
            $limits,
            $this->countUsers(),
            $this->countEmployees(),
            $this->countActiveDevices($current->tenantId),
        );
    }

    public function reserveUserAccount(): void
    {
        $this->lockGuard();
        $this->assertCapacity('users', 1);
        $this->assertCapacity('employees', 1);
    }

    public function reserveEmployee(): void
    {
        $this->lockGuard();
        $this->assertCapacity('employees', 1);
    }

    public function lockGuard(): void
    {
        if ($this->tenantConnection->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->tenantConnection->executeStatement('PRAGMA busy_timeout = 8000');
        }
        try {
            // The update locks the guard row for this transaction. MySQL reports zero
            // changed rows when id is already 1, so a matching row is still installed.
            $updated = $this->tenantConnection->executeStatement('UPDATE quota_guard SET id = 1 WHERE id = 1');
            if ($updated !== 1) {
                $installed = $this->tenantConnection->fetchOne('SELECT id FROM quota_guard WHERE id = 1');
                if (!is_scalar($installed) || (string) $installed !== '1') {
                    throw new InvalidTenantInputException('The tenant quota guard is not installed.');
                }
            }
        } catch (InvalidTenantInputException $exception) {
            throw $exception;
        } catch (\Doctrine\DBAL\Exception $exception) {
            throw new InvalidTenantInputException('The tenant quota guard is not installed.', 0, $exception);
        }
    }

    public function assertCapacity(string $resource, int $additional): void
    {
        $limits = $this->storedLimits($this->requireTenantId());
        $limit = match ($resource) {
            'users' => $limits['maxUsers'],
            'employees' => $limits['maxEmployees'],
            default => throw new \LogicException('Unknown quota resource.'),
        };
        if ($limit === null) {
            return;
        }
        $used = $resource === 'users' ? $this->countUsers() : $this->countEmployees();
        if ($used + $additional > $limit) {
            throw new TenantQuotaExceededException($resource, $used, $limit);
        }
    }

    /**
     * Serializes activation on the platform tenant row, then changes the device only when a seat remains.
     * An already active device does not consume another seat.
     */
    /**
     * @param callable(): void $mutate
     */
    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function withinPlatformTransaction(callable $operation): mixed
    {
        return $this->platform()->wrapInTransaction($operation);
    }

    public function claimActiveDevice(DeviceRegistration $device, callable $mutate): void
    {
        $manager = $this->platform();
        $manager->wrapInTransaction(function () use ($manager, $device, $mutate): void {
            $manager->getConnection()->executeStatement(
                'UPDATE tenants SET updated_at = updated_at WHERE id = ?',
                [$device->tenantId()],
            );
            $committed = $manager->getConnection()->fetchOne(
                'SELECT status FROM device_registry WHERE id = ?',
                [$device->id()],
            );
            if ($committed === DeviceStatus::Active->value) {
                if ($device->status() !== DeviceStatus::Active) {
                    $manager->refresh($device);
                }

                return;
            }
            $limit = $this->storedLimits($device->tenantId())['maxActiveDevices'];
            if ($limit !== null) {
                $used = $this->countActiveDevices($device->tenantId());
                if ($used >= $limit) {
                    throw new TenantQuotaExceededException('devices', $used, $limit);
                }
            }
            $mutate();
        });
    }

    /**
     * @param mixed $value
     */
    public function limitValue(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) && $value >= 0 && $value <= 1000000) {
            return $value;
        }

        throw new InvalidTenantInputException('A quota must be a non-negative integer or null.');
    }

    /**
     * @param array{maxUsers: ?int, maxEmployees: ?int, maxActiveDevices: ?int} $limits
     * @return array<string, mixed>
     */
    private function report(array $limits, ?int $users, ?int $employees, int $devices): array
    {
        return [
            'maxUsers' => $limits['maxUsers'],
            'maxEmployees' => $limits['maxEmployees'],
            'maxActiveDevices' => $limits['maxActiveDevices'],
            'usage' => [
                'users' => QuotaUsage::metric($users, $limits['maxUsers']),
                'employees' => QuotaUsage::metric($employees, $limits['maxEmployees']),
                'devices' => QuotaUsage::metric($devices, $limits['maxActiveDevices']),
            ],
        ];
    }

    /**
     * @return array{maxUsers: ?int, maxEmployees: ?int, maxActiveDevices: ?int}
     */
    private function storedLimits(string $tenantId): array
    {
        $quota = $this->platform()->find(TenantQuota::class, $tenantId);
        if (!$quota instanceof TenantQuota) {
            return ['maxUsers' => null, 'maxEmployees' => null, 'maxActiveDevices' => null];
        }

        return [
            'maxUsers' => $quota->maxUsers(),
            'maxEmployees' => $quota->maxEmployees(),
            'maxActiveDevices' => $quota->maxActiveDevices(),
        ];
    }

    private function countUsers(): int
    {
        return (int) $this->tenantConnection->fetchOne("SELECT COUNT(*) FROM users WHERE status = 'active'");
    }

    private function countEmployees(): int
    {
        return (int) $this->tenantConnection->fetchOne("SELECT COUNT(*) FROM employees WHERE status = 'active'");
    }

    private function countActiveDevices(string $tenantId): int
    {
        return (int) $this->platform()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM device_registry WHERE tenant_id = ? AND status = 'active'",
            [$tenantId],
        );
    }

    private function requireTenantId(): string
    {
        $tenantId = $this->context->current()?->tenantId;
        if ($tenantId === null || $tenantId === '') {
            throw new \LogicException('A tenant database must be selected before a quota check.');
        }

        return $tenantId;
    }

    private function platform(): EntityManagerInterface
    {
        $manager = $this->doctrine->getManager('platform');
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('The platform manager must be an entity manager.');
        }

        return $manager;
    }
}

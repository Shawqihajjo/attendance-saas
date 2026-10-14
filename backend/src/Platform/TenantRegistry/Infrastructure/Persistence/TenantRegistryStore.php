<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Infrastructure\Persistence;

use App\Platform\TenantRegistry\Domain\TenantStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

final class TenantRegistryStore
{
    public function __construct(private readonly ManagerRegistry $doctrine)
    {
    }

    public function findTenant(string $id): ?Tenant
    {
        $tenant = $this->platform()->find(Tenant::class, $id);

        return $tenant instanceof Tenant ? $tenant : null;
    }

    public function findTenantBySlug(string $slug): ?Tenant
    {
        $tenant = $this->platform()->getRepository(Tenant::class)->findOneBy(['slug' => $slug]);

        return $tenant instanceof Tenant ? $tenant : null;
    }

    public function findDomain(string $hostname): ?TenantDomain
    {
        $domain = $this->platform()->getRepository(TenantDomain::class)->findOneBy(['hostname' => $hostname]);

        return $domain instanceof TenantDomain ? $domain : null;
    }

    public function findPrimaryDomain(string $tenantId): ?TenantDomain
    {
        $domain = $this->platform()->getRepository(TenantDomain::class)->findOneBy([
            'tenantId' => $tenantId,
            'isPrimary' => true,
        ]);

        return $domain instanceof TenantDomain ? $domain : null;
    }

    public function findConnection(string $tenantId): ?TenantDatabaseConnection
    {
        $connection = $this->platform()->find(TenantDatabaseConnection::class, $tenantId);

        return $connection instanceof TenantDatabaseConnection ? $connection : null;
    }

    public function findSettings(string $tenantId): ?TenantSetting
    {
        $settings = $this->platform()->find(TenantSetting::class, $tenantId);

        return $settings instanceof TenantSetting ? $settings : null;
    }

    public function findBranding(string $tenantId): ?TenantBranding
    {
        $branding = $this->platform()->find(TenantBranding::class, $tenantId);

        return $branding instanceof TenantBranding ? $branding : null;
    }

    /**
     * @return list<TenantLocale>
     */
    public function locales(string $tenantId): array
    {
        /** @var list<TenantLocale> $locales */
        $locales = $this->platform()->getRepository(TenantLocale::class)->findBy(['tenantId' => $tenantId]);

        return $locales;
    }

    /**
     * @param list<string> $locales
     */
    public function replaceLocales(string $tenantId, array $locales, string $defaultLocale): void
    {
        $manager = $this->platform();
        $manager->wrapInTransaction(function () use ($manager, $tenantId, $locales, $defaultLocale): void {
            foreach ($this->locales($tenantId) as $locale) {
                $manager->remove($locale);
            }
            $manager->flush();
            foreach ($locales as $locale) {
                $manager->persist(new TenantLocale(Uuid::v7()->toRfc4122(), $tenantId, $locale, $locale === $defaultLocale));
            }
        });
    }

    /**
     * @return list<DeviceRegistration>
     */
    public function devicesForTenant(string $tenantId): array
    {
        /** @var list<DeviceRegistration> $devices */
        $devices = $this->platform()->getRepository(DeviceRegistration::class)->findBy(
            ['tenantId' => $tenantId],
            ['displayName' => 'ASC'],
        );

        return $devices;
    }

    public function findDevice(string $id): ?DeviceRegistration
    {
        $device = $this->platform()->find(DeviceRegistration::class, $id);

        return $device instanceof DeviceRegistration ? $device : null;
    }

    public function findPolicy(string $tenantId): ?TenantDevicePolicy
    {
        $policy = $this->platform()->find(TenantDevicePolicy::class, $tenantId);

        return $policy instanceof TenantDevicePolicy ? $policy : null;
    }

    /**
     * @return list<DeviceKey>
     */
    public function currentKeys(string $deviceId): array
    {
        /** @var list<DeviceKey> $keys */
        $keys = $this->platform()->getRepository(DeviceKey::class)->findBy([
            'deviceId' => $deviceId,
            'status' => 'current',
        ]);

        return $keys;
    }

    /**
     * @return list<PlatformAuditEntry>
     */
    public function auditsFor(string $targetId): array
    {
        /** @var list<PlatformAuditEntry> $entries */
        $entries = $this->platform()->getRepository(PlatformAuditEntry::class)->findBy(
            ['targetId' => $targetId],
            ['occurredAt' => 'DESC'],
            50,
        );

        return $entries;
    }

    /**
     * @return list<Tenant>
     */
    public function tenantsForMaintenance(): array
    {
        /** @var list<Tenant> $tenants */
        $tenants = $this->platform()->createQueryBuilder()
            ->select('tenant')
            ->from(Tenant::class, 'tenant')
            ->where('tenant.status IN (:statuses)')
            ->setParameter('statuses', [
                TenantStatus::Provisioning,
                TenantStatus::Active,
                TenantStatus::Suspended,
            ])
            ->getQuery()
            ->getResult();

        return $tenants;
    }

    /**
     * @return list<Tenant>
     */
    public function allTenants(): array
    {
        /** @var list<Tenant> $tenants */
        $tenants = $this->platform()->getRepository(Tenant::class)->findBy([], ['slug' => 'ASC']);

        return $tenants;
    }

    /**
     * @return list<string>
     */
    public function auditActions(string $targetId): array
    {
        $actions = [];
        /** @var list<PlatformAuditEntry> $entries */
        $entries = $this->platform()->getRepository(PlatformAuditEntry::class)->findBy(['targetId' => $targetId]);
        foreach ($entries as $entry) {
            $actions[] = $entry->action();
        }

        return $actions;
    }

    /**
     * @return array{total: int, tenants: list<Tenant>}
     */
    public function searchTenants(?TenantStatus $status, string $term, string $sort, bool $ascending, int $limit, int $offset): array
    {
        $columns = [
            'legalName' => 'tenant.legalName',
            'slug' => 'tenant.slug',
            'status' => 'tenant.status',
            'createdAt' => 'tenant.createdAt',
        ];
        if (!isset($columns[$sort])) {
            throw new \InvalidArgumentException('The tenant sort is invalid.');
        }
        $query = $this->platform()->createQueryBuilder()
            ->select('tenant')
            ->from(Tenant::class, 'tenant')
            ->leftJoin(TenantDomain::class, 'domain', 'WITH', 'domain.tenantId = tenant.id AND domain.isPrimary = true');
        if ($status instanceof TenantStatus) {
            $query->andWhere('tenant.status = :status')->setParameter('status', $status);
        }
        if ($term !== '') {
            $query->andWhere('tenant.slug LIKE :term OR tenant.legalName LIKE :term OR domain.hostname LIKE :term')
                ->setParameter('term', '%'.$term.'%');
        }
        $count = clone $query;
        $count->select('COUNT(tenant.id)')->resetDQLPart('orderBy');
        $total = (int) $count->getQuery()->getSingleScalarResult();
        /** @var list<Tenant> $tenants */
        $tenants = $query
            ->orderBy($columns[$sort], $ascending ? 'ASC' : 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['total' => $total, 'tenants' => $tenants];
    }

    /**
     * @param list<string> $tenantIds
     *
     * @return array<string, TenantDomain>
     */
    public function primaryDomainsFor(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }
        /** @var list<TenantDomain> $domains */
        $domains = $this->platform()->createQueryBuilder()
            ->select('domain')
            ->from(TenantDomain::class, 'domain')
            ->where('domain.tenantId IN (:ids)')
            ->andWhere('domain.isPrimary = true')
            ->setParameter('ids', $tenantIds)
            ->getQuery()
            ->getResult();
        $indexed = [];
        foreach ($domains as $domain) {
            $indexed[$domain->tenantId()] = $domain;
        }

        return $indexed;
    }

    /**
     * @param list<string> $tenantIds
     *
     * @return array<string, TenantSetting>
     */
    public function settingsFor(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }
        /** @var list<TenantSetting> $settings */
        $settings = $this->platform()->createQueryBuilder()
            ->select('setting')
            ->from(TenantSetting::class, 'setting')
            ->where('setting.tenantId IN (:ids)')
            ->setParameter('ids', $tenantIds)
            ->getQuery()
            ->getResult();
        $indexed = [];
        foreach ($settings as $setting) {
            $indexed[$setting->tenantId()] = $setting;
        }

        return $indexed;
    }

    /**
     * @param list<string> $tenantIds
     *
     * @return array<string, TenantDatabaseConnection>
     */
    public function connectionsFor(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }
        /** @var list<TenantDatabaseConnection> $connections */
        $connections = $this->platform()->createQueryBuilder()
            ->select('db')
            ->from(TenantDatabaseConnection::class, 'db')
            ->where('db.tenantId IN (:ids)')
            ->setParameter('ids', $tenantIds)
            ->getQuery()
            ->getResult();
        $indexed = [];
        foreach ($connections as $connection) {
            $indexed[$connection->tenantId()] = $connection;
        }

        return $indexed;
    }

    /**
     * @param list<string> $tenantIds
     *
     * @return list<TenantLocale>
     */
    public function localesFor(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }
        /** @var list<TenantLocale> $locales */
        $locales = $this->platform()->createQueryBuilder()
            ->select('locale')
            ->from(TenantLocale::class, 'locale')
            ->where('locale.tenantId IN (:ids)')
            ->setParameter('ids', $tenantIds)
            ->getQuery()
            ->getResult();

        return $locales;
    }

    /**
     * @param list<string> $tenantIds
     *
     * @return array<string, \DateTimeImmutable>
     */
    public function latestTenantActivity(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->platform()->createQueryBuilder()
            ->select('entry.targetId AS targetId, MAX(entry.occurredAt) AS occurredAt')
            ->from(PlatformAuditEntry::class, 'entry')
            ->where('entry.targetType = :type')
            ->andWhere('entry.targetId IN (:ids)')
            ->groupBy('entry.targetId')
            ->setParameter('type', 'tenant')
            ->setParameter('ids', $tenantIds)
            ->getQuery()
            ->getArrayResult();
        $latest = [];
        foreach ($rows as $row) {
            $targetId = $row['targetId'] ?? null;
            if (!is_string($targetId)) {
                continue;
            }
            $occurredAt = $this->activityInstant($row['occurredAt'] ?? null);
            if ($occurredAt instanceof \DateTimeImmutable) {
                $latest[$targetId] = $occurredAt;
            }
        }

        return $latest;
    }

    /**
     * @return array{total: int, entries: list<PlatformAuditEntry>}
     */
    public function searchAudits(?string $tenantId, int $limit, int $offset): array
    {
        $query = $this->platform()->createQueryBuilder()
            ->select('entry')
            ->from(PlatformAuditEntry::class, 'entry');
        if ($tenantId !== null) {
            $query->andWhere('entry.targetId = :tenantId OR entry.tenantId = :tenantId')
                ->setParameter('tenantId', $tenantId);
        }
        $count = clone $query;
        $count->select('COUNT(entry.id)')->resetDQLPart('orderBy');
        $total = (int) $count->getQuery()->getSingleScalarResult();
        /** @var list<PlatformAuditEntry> $entries */
        $entries = $query
            ->orderBy('entry.occurredAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return ['total' => $total, 'entries' => $entries];
    }

    public function persist(object $entity): void
    {
        $this->platform()->persist($entity);
    }

    public function flush(): void
    {
        $this->platform()->flush();
    }

    private function activityInstant(mixed $value): ?\DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value);
        }
        if (!is_string($value) || $value === '') {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr($value, 0, 19), new \DateTimeZone('UTC'));

        return $parsed instanceof \DateTimeImmutable ? $parsed : null;
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

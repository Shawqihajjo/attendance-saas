<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Application;

use App\Audit\AuditMetadata;
use App\Platform\TenantRegistry\Domain\InvalidTenantInputException;
use App\Platform\TenantRegistry\Domain\TenantStatus;
use App\Platform\TenantRegistry\Infrastructure\Persistence\PlatformAuditEntry;
use App\Platform\TenantRegistry\Infrastructure\Persistence\Tenant;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantDatabaseConnection;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\Shared\Time\UtcTime;

final class TenantCatalog
{
    public function __construct(private readonly TenantRegistryStore $store)
    {
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function list(mixed $status, mixed $term, mixed $sort, mixed $direction, int $limit, int $offset): array
    {
        $found = $this->store->searchTenants(
            $this->status($status),
            $this->term($term),
            $this->sort($sort),
            $this->ascending($direction),
            $limit,
            $offset,
        );

        return [
            'rows' => $this->present($found['tenants']),
            'total' => $found['total'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function one(Tenant $tenant): array
    {
        $rows = $this->present([$tenant]);

        return $rows[0];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function audits(?string $tenantId, int $limit, int $offset): array
    {
        $found = $this->store->searchAudits($tenantId, $limit, $offset);
        $rows = [];
        foreach ($found['entries'] as $entry) {
            $rows[] = $this->auditRow($entry);
        }

        return ['rows' => $rows, 'total' => $found['total']];
    }

    /**
     * @param list<Tenant> $tenants
     *
     * @return list<array<string, mixed>>
     */
    private function present(array $tenants): array
    {
        $ids = [];
        foreach ($tenants as $tenant) {
            $ids[] = $tenant->id();
        }
        $domains = $this->store->primaryDomainsFor($ids);
        $settings = $this->store->settingsFor($ids);
        $connections = $this->store->connectionsFor($ids);
        $activity = $this->store->latestTenantActivity($ids);
        $locales = [];
        $defaults = [];
        foreach ($this->store->localesFor($ids) as $locale) {
            $locales[$locale->tenantId()] ??= [];
            $locales[$locale->tenantId()][] = $locale->locale();
            if ($locale->isDefault()) {
                $defaults[$locale->tenantId()] = $locale->locale();
            }
        }
        $rows = [];
        foreach ($tenants as $tenant) {
            $id = $tenant->id();
            $enabled = $locales[$id] ?? [];
            sort($enabled);
            $lastActivity = $activity[$id] ?? null;
            $rows[] = [
                'id' => $id,
                'legalName' => $tenant->legalName(),
                'slug' => $tenant->slug(),
                'hostname' => isset($domains[$id]) ? $domains[$id]->hostname() : null,
                'status' => $tenant->status()->value,
                'defaultLocale' => $defaults[$id] ?? null,
                'enabledLocales' => $enabled,
                'timezone' => isset($settings[$id]) ? $settings[$id]->timezone() : null,
                'createdAt' => UtcTime::toIso($tenant->createdAt()),
                'provisioningStatus' => $this->provisioningStatus($tenant, $connections[$id] ?? null),
                'lastActivityAt' => $lastActivity instanceof \DateTimeImmutable ? UtcTime::toIso($lastActivity) : null,
            ];
        }

        return $rows;
    }

    private function provisioningStatus(Tenant $tenant, ?TenantDatabaseConnection $connection): string
    {
        if (!$connection instanceof TenantDatabaseConnection || $connection->credentialsState() === 'dropped') {
            return 'removed';
        }
        if ($tenant->status() === TenantStatus::Provisioning) {
            return 'incomplete';
        }

        return 'ready';
    }

    /**
     * @return array<string, mixed>
     */
    private function auditRow(PlatformAuditEntry $entry): array
    {
        $decoded = json_decode($entry->metadata(), true);
        $metadata = [];
        if (is_array($decoded)) {
            foreach (AuditMetadata::sanitize($decoded) as $key => $value) {
                if (is_string($value) && (strlen($value) > 180 || preg_match('/^[a-f0-9]{32,}$/', $value) === 1)) {
                    continue;
                }
                $metadata[$key] = $value;
            }
        }

        return [
            'occurredAt' => UtcTime::toIso($entry->occurredAt()),
            'actorType' => $entry->actorType(),
            'actorId' => $entry->actorId(),
            'action' => $entry->action(),
            'targetType' => $entry->targetType(),
            'targetId' => $entry->targetId(),
            'tenantId' => $entry->tenantId(),
            'result' => $entry->result(),
            'metadata' => $metadata,
        ];
    }

    private function status(mixed $status): ?TenantStatus
    {
        $value = $this->text($status);
        if ($value === '') {
            return null;
        }
        $parsed = TenantStatus::tryFrom($value);
        if (!$parsed instanceof TenantStatus) {
            throw new InvalidTenantInputException('Tenant status is invalid.');
        }

        return $parsed;
    }

    private function term(mixed $term): string
    {
        $value = $this->text($term);
        if (strlen($value) > 80) {
            throw new InvalidTenantInputException('The search text is too long.');
        }

        return str_replace(['%', '_', '\\'], '', $value);
    }

    private function sort(mixed $sort): string
    {
        $value = $this->text($sort);
        if ($value === '') {
            return 'createdAt';
        }
        if (!in_array($value, ['legalName', 'slug', 'status', 'createdAt'], true)) {
            throw new InvalidTenantInputException('The tenant sort is invalid.');
        }

        return $value;
    }

    private function ascending(mixed $direction): bool
    {
        $value = strtolower($this->text($direction));
        if ($value === '' || $value === 'desc') {
            return false;
        }
        if ($value === 'asc') {
            return true;
        }
        throw new InvalidTenantInputException('The sort direction is invalid.');
    }

    private function text(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (!is_string($value)) {
            throw new InvalidTenantInputException('The page request is invalid.');
        }

        return trim($value);
    }
}

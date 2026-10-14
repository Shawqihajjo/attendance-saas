<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Application;

use App\Identity\Application\OpenAccounts;
use App\Platform\TenantRegistry\Domain\InvalidTenantInputException;
use App\Platform\TenantRegistry\Domain\ProvisionTenantRequest;
use App\Platform\TenantRegistry\Domain\TenantInputValidator;
use App\Platform\TenantRegistry\Domain\TenantLifecycleException;
use App\Platform\TenantRegistry\Domain\TenantStatus;
use App\Platform\TenantRegistry\Infrastructure\Persistence\Tenant;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantDatabaseConnection;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantDomain;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\Shared\Clock\UtcClock;
use App\TenantContext\Domain\TenantDatabaseSwitcher;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class PlatformTenantAdministration
{
    public function __construct(
        private readonly TenantRegistryStore $store,
        private readonly TenantInputValidator $validator,
        private readonly TenantCatalog $catalog,
        private readonly ProvisionTenant $provision,
        private readonly TenantLifecycle $lifecycle,
        private readonly MigrateTenantDatabases $migrations,
        private readonly OpenAccounts $accounts,
        private readonly PlatformAuditRecorder $audit,
        private readonly TenantDatabaseSwitcher $switcher,
        private readonly ManagerRegistry $doctrine,
        private readonly UtcClock $clock,
        #[Autowire('%app.platform_hostname%')]
        private readonly string $platformHost,
    ) {
    }

    /**
     * @param list<string> $enabledLocales
     * @param array{email: string, displayName: string, password: string}|null $administrator
     *
     * @return array<string, mixed>
     */
    public function create(
        string $legalName,
        string $slug,
        ?string $hostname,
        string $timezone,
        string $defaultLocale,
        array $enabledLocales,
        ?array $administrator,
        string $actorId,
    ): array {
        $slug = $this->validator->slug($slug);
        $hostname ??= $slug.'.'.$this->platformHost;
        $tenant = $this->provision->execute(new ProvisionTenantRequest(
            $slug,
            $legalName,
            $hostname,
            $timezone,
            $defaultLocale,
            $actorId,
            $enabledLocales,
        ));
        $administratorCreated = null;
        if ($administrator !== null) {
            $administratorCreated = $this->openAdministrator($tenant, $administrator, $actorId);
        }
        $summary = $this->catalog->one($tenant);
        if ($administratorCreated !== null) {
            $summary['administratorCreated'] = $administratorCreated;
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $slug): array
    {
        return $this->catalog->one($this->requireKnown($slug));
    }

    public function showTenant(string $slug): Tenant
    {
        return $this->requireKnown($slug);
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    public function update(string $slug, array $changes, string $actorId): array
    {
        $tenant = $this->requireKnown($slug);
        $actorId = $this->validator->actorId($actorId);
        if (isset($changes['slug']) && $changes['slug'] !== $tenant->slug()) {
            throw new InvalidTenantInputException('Slug cannot be changed.');
        }
        $connection = $this->store->findConnection($tenant->id());
        $databaseName = $connection?->databaseName();
        $credentialsState = $connection?->credentialsState();
        $recorded = [];
        if (isset($changes['legalName'])) {
            $legalName = $this->validator->legalName($this->string($changes['legalName']));
            if ($legalName !== $tenant->legalName()) {
                $tenant->rename($legalName, $this->clock->now());
                $this->store->findBranding($tenant->id())?->rename($legalName);
                $recorded['legalName'] = $legalName;
            }
        }
        if (isset($changes['hostname'])) {
            $hostname = $this->validator->hostname($this->string($changes['hostname']), $this->platformHost);
            $domain = $this->store->findPrimaryDomain($tenant->id());
            if (!$domain instanceof TenantDomain) {
                throw new InvalidTenantInputException('The tenant has no primary hostname.');
            }
            if ($hostname !== $domain->hostname()) {
                $existing = $this->store->findDomain($hostname);
                if ($existing instanceof TenantDomain && $existing->tenantId() !== $tenant->id()) {
                    throw new InvalidTenantInputException('The hostname is already registered.');
                }
                $domain->changeHostname($hostname);
                $recorded['hostname'] = $hostname;
            }
        }
        if (isset($changes['timezone'])) {
            $timezone = $this->validator->timezone($this->string($changes['timezone']));
            $settings = $this->store->findSettings($tenant->id());
            if ($settings === null) {
                throw new InvalidTenantInputException('The tenant has no settings.');
            }
            if ($timezone !== $settings->timezone()) {
                $settings->changeTimezone($timezone);
                $recorded['timezone'] = $timezone;
            }
        }
        if (isset($changes['locales']) || isset($changes['defaultLocale'])) {
            $recorded = [...$recorded, ...$this->replaceLanguages($tenant, $changes)];
        }
        $again = $this->store->findConnection($tenant->id());
        if ($connection instanceof TenantDatabaseConnection && (!$again instanceof TenantDatabaseConnection || $again->databaseName() !== $databaseName || $again->credentialsState() !== $credentialsState)) {
            throw new \LogicException('A tenant profile change must not replace the database connection.');
        }
        if ($recorded !== []) {
            $this->audit->record('tenant.updated', 'tenant', $tenant->id(), $actorId, [
                'slug' => $tenant->slug(),
                'fields' => implode(',', array_keys($recorded)),
                'status' => $tenant->status()->value,
                ...$recorded,
            ]);
        } else {
            $this->store->flush();
        }

        return $this->catalog->one($tenant);
    }

    /**
     * @return array<string, mixed>
     */
    public function activate(string $slug, string $actorId): array
    {
        $tenant = $this->requireKnown($slug);
        if ($tenant->status() === TenantStatus::Active) {
            throw new TenantLifecycleException('The tenant is already active.');
        }
        if ($tenant->status() === TenantStatus::Suspended) {
            throw new TenantLifecycleException('A suspended tenant is reactivated, not activated.');
        }
        if ($tenant->status() !== TenantStatus::Provisioning) {
            throw new TenantLifecycleException('Only a provisioning tenant can be activated.');
        }
        $this->migrations->migrateSlug($tenant->slug(), $actorId);

        return $this->catalog->one($this->requireKnown($slug));
    }

    /**
     * @return array<string, mixed>
     */
    public function suspend(string $slug, string $actorId): array
    {
        $this->requireKnown($slug);

        return $this->catalog->one($this->lifecycle->suspend($slug, $actorId));
    }

    /**
     * @return array<string, mixed>
     */
    public function reactivate(string $slug, string $actorId): array
    {
        $this->requireKnown($slug);

        return $this->catalog->one($this->lifecycle->reactivate($slug, $actorId));
    }

    /**
     * @return array<string, mixed>
     */
    public function archive(string $slug, string $confirmation, string $actorId): array
    {
        $tenant = $this->requireKnown($slug);
        $this->confirm($tenant, $confirmation);

        return $this->catalog->one($this->lifecycle->decommission($slug, $actorId));
    }

    /**
     * @return array<string, mixed>
     */
    public function dropDatabase(string $slug, string $confirmation, string $actorId): array
    {
        $tenant = $this->requireKnown($slug);
        $this->confirm($tenant, $confirmation);
        $this->lifecycle->dropDatabase($slug, $actorId);

        return $this->catalog->one($this->requireKnown($slug));
    }

    /**
     * @return array{reachable: bool, tenant: array<string, mixed>}
     */
    public function check(string $slug, string $actorId): array
    {
        $tenant = $this->requireKnown($slug);
        $actorId = $this->validator->actorId($actorId);
        $connection = $this->store->findConnection($tenant->id());
        $reason = 'The tenant database is not available.';
        $reachable = false;
        if ($tenant->status() !== TenantStatus::Decommissioned && $connection instanceof TenantDatabaseConnection && $connection->credentialsState() === 'present') {
            try {
                $this->switcher->switchForMaintenance($tenant->id());
                $this->doctrine->getConnection('tenant')->executeQuery('SELECT 1')->fetchOne();
                $reachable = true;
                $reason = 'The tenant database answered.';
            } catch (\Throwable $exception) {
                $reason = SafeFailure::reason($exception);
            } finally {
                $this->switcher->reset();
            }
        }
        $this->audit->record('tenant.database_checked', 'tenant', $tenant->id(), $actorId, [
            'slug' => $tenant->slug(),
            'result' => $reachable ? 'success' : 'failure',
            'reason' => $reason,
        ]);

        return ['reachable' => $reachable, 'tenant' => $this->catalog->one($tenant)];
    }

    /**
     * @return array<string, mixed>
     */
    public function migrate(string $slug, string $actorId): array
    {
        $tenant = $this->requireKnown($slug);
        if ($tenant->status() === TenantStatus::Decommissioned) {
            throw new TenantLifecycleException('A decommissioned tenant is not migrated.');
        }
        $this->migrations->migrateSlug($tenant->slug(), $actorId);

        return $this->catalog->one($this->requireKnown($slug));
    }

    private function requireKnown(string $slug): Tenant
    {
        $tenant = $this->store->findTenantBySlug($this->validator->slug($slug));
        if (!$tenant instanceof Tenant) {
            throw new InvalidTenantInputException('Unknown tenant.');
        }

        return $tenant;
    }

    private function confirm(Tenant $tenant, string $confirmation): void
    {
        if ($confirmation !== $tenant->slug()) {
            throw new InvalidTenantInputException('Confirmation must match the tenant slug.');
        }
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, string>
     */
    private function replaceLanguages(Tenant $tenant, array $changes): array
    {
        $current = [];
        $default = null;
        foreach ($this->store->locales($tenant->id()) as $locale) {
            $current[] = $locale->locale();
            if ($locale->isDefault()) {
                $default = $locale->locale();
            }
        }
        $previousDefault = $default;
        $locales = $current;
        if (isset($changes['locales'])) {
            if (!is_array($changes['locales']) || !array_is_list($changes['locales'])) {
                throw new InvalidTenantInputException('Enabled languages are invalid.');
            }
            $locales = [];
            foreach ($changes['locales'] as $locale) {
                if (!is_string($locale)) {
                    throw new InvalidTenantInputException('Enabled languages are invalid.');
                }
                $locales[$this->validator->locale($locale)] = $this->validator->locale($locale);
            }
            $locales = array_values($locales);
        }
        if (isset($changes['defaultLocale'])) {
            $default = $this->validator->locale($this->string($changes['defaultLocale']));
        }
        if ($default === null || !in_array($default, $locales, true)) {
            throw new InvalidTenantInputException('The default locale must be one of the enabled locales.');
        }
        $before = $current;
        $after = $locales;
        sort($before);
        sort($after);
        if ($before === $after && $previousDefault === $default) {
            return [];
        }
        $this->store->replaceLocales($tenant->id(), $locales, $default);

        return [
            'defaultLocale' => $default,
            'locales' => implode(',', $locales),
        ];
    }

    /**
     * @param array{email: string, displayName: string, password: string} $administrator
     */
    private function openAdministrator(Tenant $tenant, array $administrator, string $actorId): bool
    {
        try {
            $this->accounts->tenantUser(
                $tenant->slug(),
                $administrator['email'],
                $administrator['displayName'],
                $administrator['password'],
                ['tenant_admin'],
            );
            $this->audit->record('tenant.administrator_created', 'tenant', $tenant->id(), $actorId, [
                'slug' => $tenant->slug(),
                'email' => $administrator['email'],
                'role' => 'tenant_admin',
            ]);

            return true;
        } catch (\Throwable $exception) {
            try {
                $this->audit->record('tenant.administrator_failed', 'tenant', $tenant->id(), $actorId, [
                    'slug' => $tenant->slug(),
                    'result' => 'failure',
                    'reason' => SafeFailure::reason($exception),
                ]);
            } catch (\Throwable) {
            }

            return false;
        }
    }

    private function string(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidTenantInputException('The request body is invalid.');
        }

        return $value;
    }
}

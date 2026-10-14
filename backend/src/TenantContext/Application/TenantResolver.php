<?php

declare(strict_types=1);

namespace App\TenantContext\Application;

use App\Platform\TenantRegistry\Domain\DeviceStatus;
use App\Platform\TenantRegistry\Domain\TenantStatus;
use App\Platform\TenantRegistry\Infrastructure\Persistence\Tenant;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\TenantContext\Domain\TenantContext;
use App\TenantContext\Domain\TenantMismatchException;
use App\TenantContext\Domain\TenantNotFoundException;
use App\TenantContext\Domain\TenantNotProvisionedException;
use App\TenantContext\Domain\TenantUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

final class TenantResolver
{
    public function __construct(
        private readonly TenantRegistryStore $store,
        #[Autowire('%app.platform_hostname%')]
        private readonly string $platformHost,
    ) {
    }

    public function isPlatformHost(Request $request): bool
    {
        $host = $this->hostHeader($request);

        return $host !== '' && $host === strtolower($this->platformHost);
    }

    public function resolve(Request $request): TenantContext
    {
        $host = $this->hostHeader($request);
        if ($host === '' || $host === strtolower($this->platformHost)) {
            throw new TenantNotFoundException();
        }
        $domain = $this->store->findDomain($host);
        if ($domain === null || !$domain->isVerified()) {
            throw new TenantNotFoundException();
        }
        $tenant = $this->store->findTenant($domain->tenantId());
        if (!$tenant instanceof Tenant) {
            throw new TenantNotFoundException();
        }
        if ($tenant->status() !== TenantStatus::Active) {
            throw new TenantUnavailableException($tenant->status()->value);
        }
        $this->assertClaimsAgree($request, $tenant);
        $this->assertSessionAgrees($request, $tenant->id());

        $settings = $this->store->findSettings($tenant->id());
        if ($settings === null) {
            throw new TenantNotFoundException();
        }
        $enabledLocales = [];
        $defaultLocale = null;
        foreach ($this->store->locales($tenant->id()) as $locale) {
            $enabledLocales[] = $locale->locale();
            if ($locale->isDefault()) {
                $defaultLocale = $locale->locale();
            }
        }
        if ($defaultLocale === null) {
            throw new TenantNotFoundException();
        }

        return new TenantContext(
            $tenant->id(),
            $tenant->slug(),
            $settings->timezone(),
            $defaultLocale,
            $enabledLocales,
            $settings->photoRequired(),
        );
    }

    public function tenantIdForActiveDevice(string $deviceId): string
    {
        if (!Uuid::isValid($deviceId)) {
            throw new TenantNotProvisionedException($deviceId);
        }
        $device = $this->store->findDevice($deviceId);
        if ($device === null || $device->status() !== DeviceStatus::Active) {
            throw new TenantNotProvisionedException($deviceId);
        }

        return $device->tenantId();
    }

    private function assertClaimsAgree(Request $request, Tenant $tenant): void
    {
        foreach ($this->clientClaims($request) as $claim) {
            if ($claim !== $tenant->id() && $claim !== $tenant->slug()) {
                throw new TenantMismatchException();
            }
        }
    }

    private function assertSessionAgrees(Request $request, string $tenantId): void
    {
        if (!$request->hasSession()) {
            return;
        }
        $sessionTenant = $request->getSession()->get('tenant_id');
        if (is_string($sessionTenant) && $sessionTenant !== '' && $sessionTenant !== $tenantId) {
            throw new TenantMismatchException();
        }
    }

    /**
     * @return list<string>
     */
    private function clientClaims(Request $request): array
    {
        $claims = [];
        $header = $request->headers->get('X-Tenant-Id');
        if (is_string($header) && $header !== '') {
            $claims[] = $header;
        }
        foreach (['tenant_id', 'tenantId'] as $key) {
            $value = $request->query->get($key);
            if (is_string($value) && $value !== '') {
                $claims[] = $value;
            }
        }
        $contentType = $request->headers->get('Content-Type');
        if (is_string($contentType) && str_contains($contentType, 'json') && $request->getContent() !== '') {
            $decoded = json_decode($request->getContent(), true);
            if (is_array($decoded)) {
                foreach (['tenant_id', 'tenantId', 'database', 'databaseName', 'dbname'] as $key) {
                    $value = $decoded[$key] ?? null;
                    if (is_string($value) && $value !== '') {
                        $claims[] = $value;
                    }
                }
            }
        }

        return $claims;
    }

    private function hostHeader(Request $request): string
    {
        $host = strtolower(trim((string) $request->headers->get('HOST', '')));
        if (str_contains($host, ':')) {
            $host = explode(':', $host, 2)[0];
        }

        return $host;
    }
}

<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Application;

use App\Platform\TenantRegistry\Domain\TenantConfiguration;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\TenantContext\Application\TenantResolver;
use App\TenantContext\Domain\TenantNotFoundException;
use Symfony\Component\HttpFoundation\Request;

final class ReadTenantConfiguration
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantRegistryStore $store,
    ) {
    }

    public function forHost(Request $request): TenantConfiguration
    {
        if ($this->resolver->isPlatformHost($request)) {
            return TenantConfiguration::platformDefault();
        }

        return $this->forTenantId($this->resolver->resolve($request)->tenantId);
    }

    public function forTenantId(string $tenantId): TenantConfiguration
    {
        $branding = $this->store->findBranding($tenantId);
        $settings = $this->store->findSettings($tenantId);
        if ($branding === null || $settings === null) {
            throw new TenantNotFoundException();
        }
        $supported = [];
        $default = null;
        foreach ($this->store->locales($tenantId) as $locale) {
            $supported[] = $locale->locale();
            if ($locale->isDefault()) {
                $default = $locale->locale();
            }
        }
        if ($default === null) {
            throw new TenantNotFoundException();
        }
        $colors = $branding->colors();

        return new TenantConfiguration(
            $branding->displayName(),
            $branding->logoUrl(),
            $branding->faviconUrl(),
            [
                'primary' => $colors['primary'],
                'secondary' => $colors['secondary'],
                'accent' => $colors['accent'],
                'background' => $colors['background'],
                'text' => $colors['text'],
            ],
            $default,
            $supported,
            $settings->timezone(),
            $settings->dateFormat(),
            $settings->timeFormat(),
            $settings->weekStartsOn(),
            $settings->photoRequired(),
            $settings->photoRetentionDays(),
        );
    }
}

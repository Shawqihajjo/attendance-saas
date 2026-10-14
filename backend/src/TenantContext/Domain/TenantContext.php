<?php

declare(strict_types=1);

namespace App\TenantContext\Domain;

final readonly class TenantContext
{
    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        public string $tenantId,
        public string $slug,
        public string $timezone,
        public string $defaultLocale,
        public array $enabledLocales,
        public bool $photoRequired,
    ) {
    }
}

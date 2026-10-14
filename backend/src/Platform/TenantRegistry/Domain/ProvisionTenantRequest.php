<?php

declare(strict_types=1);

namespace App\Platform\TenantRegistry\Domain;

/**
 * Operator input for a new tenant. Database names and passwords are not accepted here.
 */
final readonly class ProvisionTenantRequest
{
    public function __construct(
        public string $slug,
        public string $legalName,
        public string $hostname,
        public string $timezone,
        public string $defaultLocale,
        public string $actorId,
        /** @var list<string> */
        public array $enabledLocales = [],
    ) {
    }
}

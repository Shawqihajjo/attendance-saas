<?php

declare(strict_types=1);

namespace App\Integration\Action;

use App\Integration\TenantDomainEventMessage;

interface TenantIntegrationAction
{
    public function kind(): string;

    /**
     * @param array<string, string> $config
     */
    public function deliver(TenantDomainEventMessage $event, array $config): void;
}

<?php

declare(strict_types=1);

namespace App\Integration;

use App\Integration\Action\TenantIntegrationAction;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class TenantIntegrationCatalog
{
    /**
     * @param iterable<TenantIntegrationAction> $actions
     */
    public function __construct(
        #[AutowireIterator('app.integration_action')]
        private readonly iterable $actions,
    ) {
    }

    /**
     * @param array<string, string> $config
     */
    public function deliver(string $kind, TenantDomainEventMessage $event, array $config): void
    {
        foreach ($this->actions as $action) {
            if ($action->kind() === $kind) {
                $action->deliver($event, $config);

                return;
            }
        }

        throw new \RuntimeException('The integration kind is not available.');
    }
}

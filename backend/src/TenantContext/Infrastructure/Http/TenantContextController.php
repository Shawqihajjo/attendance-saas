<?php

declare(strict_types=1);

namespace App\TenantContext\Infrastructure\Http;

use App\Platform\TenantRegistry\Application\ReadTenantConfiguration;
use App\Shared\Http\ApiResponse;
use App\TenantContext\Domain\TenantContextHolder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class TenantContextController
{
    public function __construct(
        private readonly TenantContextHolder $tenants,
        private readonly ReadTenantConfiguration $configurations,
    ) {
    }

    #[Route('/api/v1/tenant/context', name: 'tenant_context', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $context = $this->tenants->current();
        if ($context === null) {
            throw new \LogicException('Tenant context was not resolved.');
        }
        $payload = $this->configurations->forTenantId($context->tenantId)->toArray();
        $payload['slug'] = $context->slug;

        return ApiResponse::ok($payload);
    }
}

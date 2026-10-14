<?php

declare(strict_types=1);

namespace App\Integration\Http;

use App\Identity\Domain\Permission;
use App\Identity\Infrastructure\Http\JsonBody;
use App\Identity\Infrastructure\Persistence\TenantAccountStore;
use App\Identity\Infrastructure\Security\AccessGate;
use App\Integration\TenantIntegrationRegistry;
use App\Platform\TenantRegistry\Domain\InvalidTenantInputException;
use App\Shared\Clock\UtcClock;
use App\Shared\Http\ApiResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class IntegrationController
{
    public function __construct(
        private readonly AccessGate $access,
        private readonly TenantIntegrationRegistry $integrations,
        private readonly TenantAccountStore $accounts,
        private readonly UtcClock $clock,
    ) {
    }

    #[Route('/api/v1/hr/integrations', name: 'hr_integrations', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $actor = $this->access->require(Permission::TenantConfigurationManage);
        $tenantId = $actor->tenantId();
        if ($tenantId === null) {
            throw new AccessDeniedException();
        }
        $rows = $this->integrations->list($tenantId);
        $this->accounts->audit('config.integrations_viewed', 'tenant_user', $actor->id(), 'tenant', $tenantId, $this->requestId($request), [], $this->clock->now());

        return ApiResponse::ok($rows);
    }

    #[Route('/api/v1/hr/integrations', name: 'hr_integration_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $actor = $this->access->require(Permission::TenantConfigurationManage);
        $tenantId = $actor->tenantId();
        if ($tenantId === null) {
            throw new AccessDeniedException();
        }
        $body = JsonBody::read($request);
        $config = $body['config'] ?? null;
        if (!is_array($config) || array_is_list($config)) {
            throw new UnprocessableEntityHttpException('The request body is invalid.');
        }
        $kind = JsonBody::string($body, 'kind');
        $eventName = JsonBody::string($body, 'eventName');
        $now = $this->clock->now();
        try {
            $id = $this->integrations->register($tenantId, $kind, $eventName, $config, $now);
        } catch (InvalidTenantInputException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }
        $this->accounts->audit('integration.configured', 'tenant_user', $actor->id(), 'tenant_integration', $id, null, [
            'kind' => $kind,
            'event' => $eventName,
        ], $now);

        return ApiResponse::ok(['id' => $id, 'status' => 'enabled'], [], 201);
    }

    private function requestId(Request $request): ?string
    {
        $requestId = $request->attributes->get('request_id');

        return is_string($requestId) ? $requestId : null;
    }
}

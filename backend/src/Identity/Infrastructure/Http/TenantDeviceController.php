<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Domain\Permission;
use App\Identity\Infrastructure\Persistence\TenantAccountStore;
use App\Identity\Infrastructure\Security\AccessGate;
use App\Platform\Device\DeviceAdministration;
use App\Shared\Clock\UtcClock;
use App\Shared\Http\ApiResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class TenantDeviceController
{
    public function __construct(
        private readonly AccessGate $access,
        private readonly DeviceAdministration $devices,
        private readonly TenantAccountStore $accounts,
        private readonly UtcClock $clock,
    ) {
    }

    #[Route('/api/v1/hr/terminals', name: 'hr_terminals', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return ApiResponse::ok($this->devices->list($this->tenantId()));
    }

    #[Route('/api/v1/hr/terminals', name: 'hr_terminal_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $actor = $this->access->require(Permission::TerminalsManage);
        $body = JsonBody::read($request);
        $timezone = $body['timezone'] ?? null;
        $location = $body['location'] ?? null;
        $certificate = $body['certificateRef'] ?? null;
        $activation = $body['requireActivation'] ?? false;
        if (($timezone !== null && !is_string($timezone)) || ($location !== null && !is_string($location)) || ($certificate !== null && !is_string($certificate)) || !is_bool($activation)) {
            throw new UnprocessableEntityHttpException('The request body is invalid.');
        }

        return ApiResponse::ok($this->devices->register(
            $this->tenantId(),
            JsonBody::string($body, 'displayName'),
            $location,
            $timezone,
            $certificate,
            $activation,
            $actor->id(),
        ), [], 201);
    }

    #[Route('/api/v1/hr/terminals/{id}/activate', name: 'hr_terminal_activate', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function activate(string $id): JsonResponse
    {
        $actor = $this->access->require(Permission::TerminalsManage);

        return ApiResponse::ok($this->devices->activate($this->tenantId(), $id, $actor->id()));
    }

    #[Route('/api/v1/hr/terminals/{id}/suspend', name: 'hr_terminal_suspend', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function suspend(string $id): JsonResponse
    {
        $actor = $this->access->require(Permission::TerminalsManage);

        return ApiResponse::ok($this->devices->suspend($this->tenantId(), $id, $actor->id()));
    }

    #[Route('/api/v1/hr/terminals/{id}/revoke', name: 'hr_terminal_revoke', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function revoke(string $id): JsonResponse
    {
        $actor = $this->access->require(Permission::TerminalsManage);

        return ApiResponse::ok($this->devices->revoke($this->tenantId(), $id, $actor->id()));
    }

    #[Route('/api/v1/hr/terminals/{id}/credentials', name: 'hr_terminal_rotate', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function rotate(string $id): JsonResponse
    {
        $actor = $this->access->require(Permission::TerminalsManage);

        return ApiResponse::ok($this->devices->rotate($this->tenantId(), $id, $actor->id()));
    }

    #[Route('/api/v1/hr/terminals/{id}/audit', name: 'hr_terminal_audit', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function audit(Request $request, string $id): JsonResponse
    {
        $actor = $this->access->require(Permission::TerminalsManage);
        $this->record($request, $actor->id(), 'device.audit_viewed', 'device', $id, []);

        return ApiResponse::ok($this->devices->audit($this->tenantId(), $id));
    }

    #[Route('/api/v1/hr/device-policy', name: 'hr_device_policy', methods: ['GET'])]
    public function policy(Request $request): JsonResponse
    {
        $actor = $this->access->require(Permission::TerminalsManage);
        $this->record($request, $actor->id(), 'config.device_policy_viewed', 'tenant', $this->tenantId(), []);

        return ApiResponse::ok($this->devices->policy($this->tenantId()));
    }

    #[Route('/api/v1/hr/device-policy', name: 'hr_device_policy_replace', methods: ['PUT'])]
    public function replacePolicy(Request $request): JsonResponse
    {
        $actor = $this->access->require(Permission::TerminalsManage);
        $body = JsonBody::read($request);

        return ApiResponse::ok($this->devices->replacePolicy(
            $this->tenantId(),
            JsonBody::stringList($body, 'allowedNetworks'),
            JsonBody::stringList($body, 'allowedIpRanges'),
            JsonBody::stringList($body, 'allowedAppVersions'),
            $actor->id(),
        ));
    }

    /**
     * @param array<string, scalar|null> $metadata
     */
    private function record(Request $request, string $actorId, string $action, string $targetType, string $targetId, array $metadata): void
    {
        $requestId = $request->attributes->get('request_id');
        $this->accounts->audit(
            $action,
            'tenant_user',
            $actorId,
            $targetType,
            $targetId,
            is_string($requestId) ? $requestId : null,
            $metadata,
            $this->clock->now(),
        );
    }

    private function tenantId(): string
    {
        $actor = $this->access->require(Permission::TerminalsManage);
        $tenantId = $actor->tenantId();
        if ($tenantId === null) {
            throw new AccessDeniedException();
        }

        return $tenantId;
    }
}

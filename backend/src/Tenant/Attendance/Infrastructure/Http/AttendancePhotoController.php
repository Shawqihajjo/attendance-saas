<?php

declare(strict_types=1);

namespace App\Tenant\Attendance\Infrastructure\Http;

use App\Identity\Domain\Permission;
use App\Identity\Infrastructure\Http\JsonBody;
use App\Identity\Infrastructure\Security\AccessGate;
use App\Platform\TenantRegistry\Infrastructure\Persistence\TenantRegistryStore;
use App\Shared\Clock\UtcClock;
use App\Shared\Http\ApiResponse;
use App\Tenant\Attendance\Infrastructure\AttendancePhotoAccess;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class AttendancePhotoController
{
    public function __construct(
        private readonly AccessGate $access,
        private readonly AttendancePhotoAccess $photos,
        private readonly TenantRegistryStore $registry,
        private readonly UtcClock $clock,
    ) {
    }

    #[Route('/api/v1/hr/attendance-photos/{id}', name: 'hr_attendance_photo', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function view(Request $request, string $id): Response
    {
        $actor = $this->access->actor();
        $tenantId = $actor->tenantId();
        if ($tenantId === null) {
            throw new AccessDeniedException();
        }

        return $this->photos->view($tenantId, $id, $this->clock->now(), $this->requestId($request));
    }

    #[Route('/api/v1/hr/attendance-photos/{id}', name: 'hr_attendance_photo_delete', methods: ['DELETE'], requirements: ['id' => Requirement::UUID])]
    public function delete(Request $request, string $id): Response
    {
        $actor = $this->access->require(Permission::EmployeesManage);
        $tenantId = $actor->tenantId();
        if ($tenantId === null) {
            throw new AccessDeniedException();
        }
        $this->photos->delete($tenantId, $id, $actor->id(), $this->clock->now(), $this->requestId($request));

        return ApiResponse::ok(['deleted' => true]);
    }

    #[Route('/api/v1/hr/settings/photo-retention', name: 'hr_photo_retention', methods: ['PATCH'])]
    public function retention(Request $request): Response
    {
        $actor = $this->access->require(Permission::TenantSettingsManage);
        $tenantId = $actor->tenantId();
        if ($tenantId === null) {
            throw new AccessDeniedException();
        }
        $body = JsonBody::read($request);
        if (!array_key_exists('retentionDays', $body)) {
            throw new UnprocessableEntityHttpException('The request body is invalid.');
        }
        $days = $body['retentionDays'];
        if ($days !== null && (!is_int($days) || $days < 1 || $days > 3650)) {
            throw new UnprocessableEntityHttpException('Retention must be a number of days from 1 to 3650, or null.');
        }
        $settings = $this->registry->findSettings($tenantId);
        if ($settings === null) {
            throw new AccessDeniedHttpException();
        }
        $settings->changePhotoRetention($days);
        $this->registry->flush();

        return ApiResponse::ok(['retentionDays' => $days]);
    }

    private function requestId(Request $request): ?string
    {
        $requestId = $request->attributes->get('request_id');

        return is_string($requestId) && $requestId !== '' ? $requestId : null;
    }
}

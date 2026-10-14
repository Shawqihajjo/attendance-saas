<?php

declare(strict_types=1);

namespace App\Tenant\Directory\Infrastructure\Http;

use App\Identity\Domain\Permission;
use App\Identity\Infrastructure\Http\JsonBody;
use App\Identity\Infrastructure\Persistence\TenantAccountStore;
use App\Identity\Infrastructure\Security\AccessGate;
use App\Platform\TenantRegistry\Domain\InvalidTenantInputException;
use App\Shared\Clock\UtcClock;
use App\Shared\Http\ApiResponse;
use App\Tenant\Directory\Infrastructure\EmployeeDirectory;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class EmployeeController
{
    public function __construct(
        private readonly AccessGate $access,
        private readonly EmployeeDirectory $directory,
        private readonly TenantAccountStore $accounts,
        private readonly UtcClock $clock,
    ) {
    }

    #[Route('/api/v1/hr/employees', name: 'hr_employee_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $actor = $this->access->require(Permission::EmployeesManage);
        $employee = $this->run(fn (): array => $this->directory->create(JsonBody::read($request), $this->clock->now()));
        $this->audit($request, $actor->id(), 'employee.created', (string) $employee['id'], [
            'employeeNumber' => $employee['employeeNumber'],
        ]);

        return ApiResponse::ok($employee, [], 201);
    }

    #[Route('/api/v1/hr/employees/{id}', name: 'hr_employee_update', methods: ['PATCH'], requirements: ['id' => Requirement::UUID])]
    public function update(Request $request, string $id): JsonResponse
    {
        $actor = $this->access->require(Permission::EmployeesManage);
        $employee = $this->run(fn (): array => $this->directory->update($id, JsonBody::read($request), $this->clock->now()));
        $this->audit($request, $actor->id(), 'employee.updated', $id, [
            'employeeNumber' => $employee['employeeNumber'],
        ]);

        return ApiResponse::ok($employee);
    }

    #[Route('/api/v1/hr/employees/{id}/status', name: 'hr_employee_status', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function status(Request $request, string $id): JsonResponse
    {
        $actor = $this->access->require(Permission::EmployeesManage);
        $body = JsonBody::read($request);
        $status = $body['status'] ?? null;
        if (!is_string($status)) {
            throw new UnprocessableEntityHttpException('The request body is invalid.');
        }
        $employee = $this->run(fn (): array => $this->directory->changeStatus($id, $status, $this->clock->now()));
        $this->audit($request, $actor->id(), 'employee.status_changed', $id, ['status' => $employee['status']]);

        return ApiResponse::ok($employee);
    }

    #[Route('/api/v1/hr/employees/{id}/attendance-code', name: 'hr_employee_attendance_code', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function attendanceCode(Request $request, string $id): JsonResponse
    {
        $actor = $this->access->require(Permission::EmployeesManage);
        $employee = $this->directory->resetAttendanceIdentifier($id, $this->clock->now());
        $this->audit($request, $actor->id(), 'employee.attendance_identifier_reset', $id, [
            'employeeNumber' => $employee['employeeNumber'],
        ]);

        return ApiResponse::ok($employee);
    }

    /**
     * @param callable(): array<string, mixed> $action
     *
     * @return array<string, mixed>
     */
    private function run(callable $action): array
    {
        try {
            return $action();
        } catch (InvalidTenantInputException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }
    }

    /**
     * @param array<string, scalar|null> $metadata
     */
    private function audit(Request $request, string $actorId, string $action, string $employeeId, array $metadata): void
    {
        $requestId = $request->attributes->get('request_id');
        $this->accounts->audit(
            $action,
            'tenant_user',
            $actorId,
            'employee',
            $employeeId,
            is_string($requestId) ? $requestId : null,
            $metadata,
            $this->clock->now(),
        );
    }
}

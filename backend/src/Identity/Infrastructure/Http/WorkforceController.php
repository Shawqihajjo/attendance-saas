<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Application\AccountInput;
use App\Identity\Domain\Permission;
use App\Identity\Infrastructure\Persistence\TenantAccountStore;
use App\Identity\Infrastructure\Security\AccessGate;
use App\Platform\TenantRegistry\Domain\InvalidTenantInputException;
use App\Shared\Clock\UtcClock;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\PageQuery;
use App\Shared\Time\TimeDisplay;
use App\Shared\Time\TimeZoneChoice;
use App\Tenant\Attendance\CorrectionConflictException;
use App\Tenant\Attendance\Infrastructure\AttendanceCorrectionWorkflow;
use App\Tenant\Attendance\Infrastructure\AttendanceDashboard;
use App\Tenant\Attendance\Infrastructure\AttendanceReportQuery;
use App\Tenant\Attendance\Infrastructure\AttendanceTimeReport;
use App\Tenant\Directory\Infrastructure\EmployeeDirectory;
use App\TenantContext\Application\TenantResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

final class WorkforceController
{
    public function __construct(
        private readonly AccessGate $access,
        private readonly TenantAccountStore $accounts,
        private readonly EmployeeDirectory $directory,
        private readonly AccountInput $input,
        private readonly UtcClock $clock,
        private readonly AttendanceTimeReport $times,
        private readonly AttendanceDashboard $dashboards,
        private readonly AttendanceCorrectionWorkflow $corrections,
        private readonly TenantResolver $tenants,
    ) {
    }

    #[Route('/api/v1/hr/employees', name: 'hr_employees', methods: ['GET'])]
    public function employees(): JsonResponse
    {
        if ($this->access->granted(Permission::EmployeesManage)) {
            $this->access->require(Permission::EmployeesManage);

            return ApiResponse::ok($this->directory->list(null));
        }
        $actor = $this->access->require(Permission::EmployeesViewAssigned);
        $managerId = $actor->employeeId();
        if ($managerId === null) {
            return ApiResponse::ok([]);
        }

        return ApiResponse::ok($this->directory->list($managerId));
    }

    #[Route('/api/v1/hr/employees/{id}', name: 'hr_employee', methods: ['GET'], requirements: ['id' => Requirement::UUID])]
    public function employee(string $id): JsonResponse
    {
        if ($this->directory->find($id) === null) {
            throw new NotFoundHttpException('Employee was not found.');
        }
        $this->access->requireEmployeeView($id);

        return ApiResponse::ok($this->directory->find($id));
    }

    #[Route('/api/v1/hr/manager-assignments', name: 'hr_manager_assignment', methods: ['POST'])]
    public function assignManager(Request $request): JsonResponse
    {
        $actor = $this->access->require(Permission::EmployeesManage);
        $body = JsonBody::read($request);
        $managerId = JsonBody::string($body, 'managerEmployeeId');
        $employeeId = JsonBody::string($body, 'employeeId');
        if (!Uuid::isValid($managerId) || !Uuid::isValid($employeeId)) {
            throw new UnprocessableEntityHttpException('The assignment is invalid.');
        }
        $this->directory->assignChief($employeeId, $managerId, $this->clock->now());
        $this->audit($request, $actor->id(), 'employee.manager_assigned', 'employee', $employeeId, [
            'managerEmployeeId' => $managerId,
        ]);

        return ApiResponse::ok(['managerEmployeeId' => $managerId, 'employeeId' => $employeeId], [], 201);
    }

    #[Route('/api/v1/hr/attendance', name: 'hr_attendance', methods: ['GET'])]
    public function attendance(Request $request): JsonResponse
    {
        if ($this->access->granted(Permission::EmployeesManage) || $this->access->granted(Permission::ReportsView)) {
            $managerEmployeeId = null;
        } elseif ($this->access->granted(Permission::AttendanceReview) || $this->access->granted(Permission::AttendanceViewTeam)) {
            $managerEmployeeId = $this->access->actor()->employeeId() ?? '';
        } else {
            throw new AccessDeniedException();
        }
        try {
            $view = $this->times->present(
                new TimeZoneChoice(TimeDisplay::Tenant, $this->tenants->resolve($request)->timezone),
                $managerEmployeeId,
                $this->optionalQuery($request, 'date'),
                $this->optionalQuery($request, 'deviceId'),
                $this->optionalQuery($request, 'employeeId'),
                PageQuery::limit($request, 50),
                PageQuery::offset($request),
            );
        } catch (\InvalidArgumentException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }

        return ApiResponse::ok([
            'timezone' => $view['timezone'],
            'records' => $view['records'],
            'page' => $view['page'],
        ]);
    }

    #[Route('/api/v1/hr/attendance-corrections', name: 'hr_corrections', methods: ['GET'])]
    public function corrections(Request $request): JsonResponse
    {
        if (!$this->access->granted(Permission::AttendanceCorrectionsManage)
            && !$this->access->granted(Permission::EmployeesManage)
            && !$this->access->granted(Permission::AttendanceCorrectionDecide)
            && !$this->access->granted(Permission::AttendanceReview)) {
            throw new AccessDeniedException();
        }

        $page = $this->accounts->listCorrections(null, $this->correctionScope(), PageQuery::limit($request, 50), PageQuery::offset($request));

        return ApiResponse::ok($page['records'], ['page' => $page['page']]);
    }

    #[Route('/api/v1/hr/dashboard', name: 'hr_dashboard', methods: ['GET'])]
    public function dashboard(Request $request): JsonResponse
    {
        if ($this->access->granted(Permission::EmployeesManage) || $this->access->granted(Permission::ReportsView)) {
            $managerEmployeeId = null;
        } elseif ($this->access->granted(Permission::AttendanceViewTeam) || $this->access->granted(Permission::AttendanceReview) || $this->access->granted(Permission::ReportsViewTeam)) {
            $managerEmployeeId = $this->access->actor()->employeeId() ?? '';
        } else {
            throw new AccessDeniedException();
        }
        try {
            $view = $this->dashboards->organization(
                $managerEmployeeId,
                $this->timeChoice($request, $this->tenants->resolve($request)->timezone),
                $this->optionalQuery($request, 'date'),
                $this->optionalQuery($request, 'deviceId'),
                $this->clock->now(),
                PageQuery::limit($request, 25),
                PageQuery::offset($request),
            );
        } catch (\InvalidArgumentException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }

        return ApiResponse::ok($view);
    }

    private function correctionScope(): ?string
    {
        if ($this->access->granted(Permission::AttendanceCorrectionsManage) || $this->access->granted(Permission::EmployeesManage)) {
            return null;
        }
        $actor = $this->access->actor();

        return $actor->employeeId() ?? '';
    }

    #[Route('/api/v1/hr/reports', name: 'hr_reports', methods: ['GET'])]
    public function reports(Request $request): JsonResponse
    {
        $managerEmployeeId = null;
        if ($this->access->granted(Permission::ReportsView)) {
            $scope = 'tenant';
        } elseif ($this->access->granted(Permission::ReportsViewTeam)) {
            $scope = 'team';
            $managerEmployeeId = $this->access->require(Permission::ReportsViewTeam)->employeeId() ?? '';
        } else {
            throw new AccessDeniedException();
        }

        try {
            $view = $this->times->search(
                $this->timeChoice($request, $this->tenants->resolve($request)->timezone),
                $managerEmployeeId,
                new AttendanceReportQuery(
                    $this->optionalQuery($request, 'employeeId'),
                    $this->optionalQuery($request, 'teamId'),
                    $this->optionalQuery($request, 'departmentId'),
                    $this->optionalQuery($request, 'deviceId'),
                    $this->optionalQuery($request, 'date'),
                    $this->optionalQuery($request, 'from'),
                    $this->optionalQuery($request, 'to'),
                    $this->optionalQuery($request, 'eventType'),
                    $this->optionalQuery($request, 'status'),
                    PageQuery::limit($request, 50),
                    PageQuery::offset($request),
                ),
            );
        } catch (\InvalidArgumentException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }

        return ApiResponse::ok([
            'scope' => $scope,
            'display' => $view['display'],
            'timezone' => $view['timezone'],
            'records' => $view['records'],
            'page' => $view['page'],
        ]);
    }

    #[Route('/api/v1/hr/attendance-corrections/{id}/decision', name: 'hr_correction_decision', methods: ['POST'], requirements: ['id' => Requirement::UUID])]
    public function decide(Request $request, string $id): JsonResponse
    {
        $correction = $this->accounts->correction($id);
        if ($correction === null) {
            throw new NotFoundHttpException('Correction request was not found.');
        }
        if (!in_array($correction['status'], ['requested', 'pending'], true)) {
            throw new StateConflictException('This correction request is already decided.');
        }
        $actor = $this->access->requireCorrectionDecision($correction['employeeId']);
        $body = JsonBody::read($request);
        try {
            $decision = $this->input->decision(JsonBody::string($body, 'decision'));
            $comment = $this->input->note(JsonBody::optionalString($body, 'comment'), 500);
            $adjustmentId = $this->corrections->decide(
                $id,
                $decision,
                $actor->id(),
                $comment,
                $this->tenants->resolve($request)->timezone,
                $this->clock->now(),
            );
        } catch (InvalidTenantInputException|\InvalidArgumentException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        } catch (CorrectionConflictException $exception) {
            throw new StateConflictException($exception->getMessage());
        }
        $this->audit($request, $actor->id(), 'attendance.correction_decided', 'attendance_correction', $id, [
            'correctionId' => $id,
            'decision' => $decision,
            'employeeId' => $correction['employeeId'],
            'adjustmentId' => $adjustmentId,
        ]);

        return ApiResponse::ok(['id' => $id, 'status' => $decision]);
    }

    #[Route('/api/v1/hr/departments', name: 'hr_departments', methods: ['GET'])]
    public function departments(): JsonResponse
    {
        $this->access->require(Permission::OrganizationManage);

        return ApiResponse::ok($this->accounts->departments());
    }

    #[Route('/api/v1/hr/teams', name: 'hr_teams', methods: ['GET'])]
    public function teams(): JsonResponse
    {
        $this->access->require(Permission::OrganizationManage);

        return ApiResponse::ok($this->directory->teams());
    }

    #[Route('/api/v1/hr/teams', name: 'hr_team_create', methods: ['POST'])]
    public function createTeam(Request $request): JsonResponse
    {
        $actor = $this->access->require(Permission::OrganizationManage);
        $body = JsonBody::read($request);
        $departmentId = $body['departmentId'] ?? null;
        if ($departmentId !== null && (!is_string($departmentId) || !Uuid::isValid($departmentId))) {
            throw new UnprocessableEntityHttpException('The request body is invalid.');
        }
        try {
            $name = $this->input->displayName(JsonBody::string($body, 'name'));
            $id = $this->directory->addTeam($name, is_string($departmentId) ? $departmentId : null, $this->clock->now());
        } catch (InvalidTenantInputException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }
        $this->audit($request, $actor->id(), 'team.created', 'team', $id, ['name' => $name]);

        return ApiResponse::ok(['id' => $id, 'name' => $name, 'departmentId' => $departmentId], [], 201);
    }

    #[Route('/api/v1/hr/departments', name: 'hr_department_create', methods: ['POST'])]
    public function createDepartment(Request $request): JsonResponse
    {
        $actor = $this->access->require(Permission::OrganizationManage);
        try {
            $name = $this->input->displayName(JsonBody::string(JsonBody::read($request), 'name'));
        } catch (InvalidTenantInputException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }
        $id = $this->accounts->addDepartment($name, $this->clock->now());
        $this->audit($request, $actor->id(), 'department.created', 'department', $id, ['name' => $name]);

        return ApiResponse::ok(['id' => $id, 'name' => $name], [], 201);
    }

    #[Route('/api/v1/hr/schedules', name: 'hr_schedules', methods: ['GET'])]
    public function schedules(): JsonResponse
    {
        $this->access->require(Permission::SchedulesManage);

        return ApiResponse::ok($this->accounts->schedules());
    }

    #[Route('/api/v1/hr/schedules', name: 'hr_schedule_create', methods: ['POST'])]
    public function createSchedule(Request $request): JsonResponse
    {
        $actor = $this->access->require(Permission::SchedulesManage);
        $body = JsonBody::read($request);
        $employeeId = JsonBody::string($body, 'employeeId');
        if (!Uuid::isValid($employeeId) || $this->accounts->employee($employeeId) === null) {
            throw new NotFoundHttpException('Employee was not found.');
        }
        try {
            $name = $this->input->displayName(JsonBody::string($body, 'name'));
        } catch (InvalidTenantInputException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }
        $id = $this->accounts->addSchedule($employeeId, $name, $this->clock->now());
        $this->audit($request, $actor->id(), 'schedule.created', 'schedule', $id, [
            'employeeId' => $employeeId,
            'name' => $name,
        ]);

        return ApiResponse::ok(['id' => $id, 'employeeId' => $employeeId, 'name' => $name], [], 201);
    }

    /**
     * @param array<string, scalar|null> $metadata
     */
    private function audit(Request $request, string $actorId, string $action, string $targetType, string $targetId, array $metadata): void
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

    private function timeChoice(Request $request, string $tenantTimeZone): TimeZoneChoice
    {
        return new TimeZoneChoice(
            TimeDisplay::fromRequest($this->optionalQuery($request, 'display')),
            $tenantTimeZone,
            null,
            $this->optionalQuery($request, 'browserTimezone'),
            $this->optionalQuery($request, 'timezone'),
        );
    }

    private function optionalQuery(Request $request, string $name): ?string
    {
        if (!$request->query->has($name)) {
            return null;
        }
        $value = $request->query->get($name);
        if (!is_string($value)) {
            throw new UnprocessableEntityHttpException('The time request is invalid.');
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}

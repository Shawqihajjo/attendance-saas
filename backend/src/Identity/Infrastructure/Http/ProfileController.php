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
use App\Tenant\Attendance\AttendanceRejectedException;
use App\Tenant\Attendance\CorrectionConflictException;
use App\Tenant\Attendance\Infrastructure\AttendanceCorrectionWorkflow;
use App\Tenant\Attendance\Infrastructure\AttendanceDashboard;
use App\Tenant\Attendance\Infrastructure\AttendanceTimeReport;
use App\TenantContext\Application\TenantResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class ProfileController
{
    public function __construct(
        private readonly AccessGate $access,
        private readonly TenantAccountStore $accounts,
        private readonly AccountInput $input,
        private readonly UtcClock $clock,
        private readonly AttendanceTimeReport $times,
        private readonly AttendanceDashboard $dashboards,
        private readonly AttendanceCorrectionWorkflow $corrections,
        private readonly TenantResolver $tenants,
    ) {
    }

    #[Route('/api/v1/me/profile', name: 'me_profile', methods: ['GET'])]
    public function profile(): JsonResponse
    {
        $actor = $this->access->require(Permission::ProfileViewOwn);

        return ApiResponse::ok([
            'email' => $actor->email(),
            'displayName' => $actor->displayName(),
            'roles' => $actor->appRoles(),
            'employeeId' => $actor->employeeId(),
        ]);
    }

    #[Route('/api/v1/me/attendance', name: 'me_attendance', methods: ['GET'])]
    public function attendance(Request $request): JsonResponse
    {
        $actor = $this->access->require(Permission::AttendanceViewOwn);
        $employeeId = $actor->employeeId();
        if ($employeeId === null) {
            return ApiResponse::ok(['records' => [], 'timezone' => null]);
        }
        try {
            $view = $this->times->present(
                new TimeZoneChoice(TimeDisplay::Tenant, $this->tenants->resolve($request)->timezone),
                null,
                $this->optionalQuery($request, 'date'),
                null,
                $employeeId,
                PageQuery::limit($request, 50),
                PageQuery::offset($request),
            );
        } catch (\InvalidArgumentException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }

        return ApiResponse::ok([
            'records' => $view['records'],
            'timezone' => $view['timezone'],
            'page' => $view['page'],
        ]);
    }

    #[Route('/api/v1/me/dashboard', name: 'me_dashboard', methods: ['GET'])]
    public function dashboard(Request $request): JsonResponse
    {
        $actor = $this->access->require(Permission::AttendanceViewOwn);
        $employeeId = $actor->employeeId();
        if ($employeeId === null) {
            return ApiResponse::ok($this->dashboards->blankEmployee());
        }
        try {
            $view = $this->dashboards->employee(
                $employeeId,
                $this->timeChoice($request, $this->tenants->resolve($request)->timezone),
                $this->optionalQuery($request, 'date'),
                $this->optionalQuery($request, 'deviceId'),
                $this->clock->now(),
                PageQuery::limit($request, 20),
                PageQuery::offset($request),
            );
        } catch (\InvalidArgumentException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        }

        return ApiResponse::ok($view);
    }

    #[Route('/api/v1/me/attendance-corrections', name: 'me_corrections', methods: ['GET'])]
    public function corrections(Request $request): JsonResponse
    {
        $actor = $this->access->require(Permission::AttendanceCorrectionRequest);
        $employeeId = $actor->employeeId();
        if ($employeeId === null) {
            return ApiResponse::ok([]);
        }
        $page = $this->accounts->listCorrections($employeeId, null, PageQuery::limit($request, 50), PageQuery::offset($request));

        return ApiResponse::ok($page['records'], ['page' => $page['page']]);
    }

    #[Route('/api/v1/me/attendance-corrections', name: 'me_correction_request', methods: ['POST'])]
    public function requestCorrection(Request $request): JsonResponse
    {
        $actor = $this->access->require(Permission::AttendanceCorrectionRequest);
        if ($actor->employeeId() === null) {
            throw new UnprocessableEntityHttpException('This account is not linked to an employee.');
        }
        $body = JsonBody::read($request);
        try {
            $reason = $this->input->reason(JsonBody::string($body, 'reason'));
            $comment = $this->input->note(JsonBody::optionalString($body, 'comment'), 500);
            $supporting = $this->input->note(JsonBody::optionalString($body, 'supportingInformation'), 1000);
            $submitted = $this->corrections->submit(
                $actor->employeeId(),
                $actor->id(),
                JsonBody::string($body, 'localDate'),
                JsonBody::string($body, 'localTime'),
                JsonBody::string($body, 'action'),
                $reason,
                $comment,
                $supporting,
                $this->tenants->resolve($request)->timezone,
                $this->clock->now(),
            );
        } catch (InvalidTenantInputException|AttendanceRejectedException|\InvalidArgumentException $exception) {
            throw new UnprocessableEntityHttpException($exception->getMessage(), $exception);
        } catch (CorrectionConflictException $exception) {
            throw new StateConflictException($exception->getMessage());
        }
        $requestId = $request->attributes->get('request_id');
        $this->accounts->audit(
            'attendance.correction_requested',
            'tenant_user',
            $actor->id(),
            'attendance_correction',
            $submitted['id'],
            is_string($requestId) ? $requestId : null,
            [
                'correctionId' => $submitted['id'],
                'employeeId' => $actor->employeeId(),
                'localDate' => JsonBody::string($body, 'localDate'),
                'action' => JsonBody::string($body, 'action'),
                'requestedOccurredAt' => $submitted['requestedOccurredAt'],
            ],
            $this->clock->now(),
        );

        return ApiResponse::ok(['id' => $submitted['id'], 'status' => 'requested'], [], 201);
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

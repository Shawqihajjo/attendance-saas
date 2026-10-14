<?php

declare(strict_types=1);

namespace App\Identity\Domain;

final class AccessCatalog
{
    public const EMPLOYEE = 'employee';
    public const MANAGER = 'manager';
    public const HR = 'hr';
    public const TENANT_ADMIN = 'tenant_admin';
    public const PLATFORM_ADMIN = 'platform_admin';

    /**
     * @return list<string>
     */
    public static function tenantRoles(): array
    {
        return [self::EMPLOYEE, self::MANAGER, self::HR, self::TENANT_ADMIN];
    }

    public static function isTenantRole(string $role): bool
    {
        return in_array($role, self::tenantRoles(), true);
    }

    public static function securityRole(string $role): string
    {
        return 'ROLE_'.strtoupper($role);
    }

    /**
     * @param list<string> $roles
     *
     * @return list<string>
     */
    public static function permissionsFor(array $roles): array
    {
        $granted = [];
        foreach ($roles as $role) {
            foreach (self::catalog()[$role] ?? [] as $permission) {
                $granted[$permission] = $permission;
            }
        }

        return array_values($granted);
    }

    /**
     * Chief and manager are the same role. Permissions are not stored as a copy of the role name.
     *
     * @return array<string, list<string>>
     */
    public static function catalog(): array
    {
        $employee = [
            Permission::ProfileViewOwn->value,
            Permission::AttendanceViewOwn->value,
            Permission::AttendanceCorrectionRequest->value,
        ];
        $manager = [
            ...$employee,
            Permission::EmployeesViewAssigned->value,
            Permission::AttendanceViewTeam->value,
            Permission::AttendanceReview->value,
            Permission::AttendanceCorrectionDecide->value,
            Permission::ReportsViewTeam->value,
        ];
        $hr = [
            ...$manager,
            Permission::EmployeesManage->value,
            Permission::OrganizationManage->value,
            Permission::SchedulesManage->value,
            Permission::AttendanceCorrectionsManage->value,
            Permission::ReportsView->value,
            Permission::AuditView->value,
            Permission::TenantSettingsManage->value,
        ];
        $tenantAdmin = [
            ...$hr,
            Permission::TenantConfigurationManage->value,
            Permission::UsersManage->value,
            Permission::TerminalsManage->value,
            Permission::PermissionsManage->value,
            Permission::BrandingManage->value,
            Permission::LocalesManage->value,
            Permission::TimezoneManage->value,
        ];

        return [
            self::EMPLOYEE => $employee,
            self::MANAGER => $manager,
            self::HR => $hr,
            self::TENANT_ADMIN => $tenantAdmin,
            self::PLATFORM_ADMIN => [
                Permission::TenantsManage->value,
                Permission::TenantLifecycleManage->value,
                Permission::PlatformAdminister->value,
                Permission::PlatformDatabaseExplore->value,
            ],
        ];
    }
}

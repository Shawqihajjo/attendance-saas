<?php

declare(strict_types=1);

namespace App\Identity\Domain;

enum Permission: string
{
    case ProfileViewOwn = 'profile.view_own';
    case AttendanceViewOwn = 'attendance.view_own';
    case AttendanceCorrectionRequest = 'attendance.correction.request';
    case EmployeesViewAssigned = 'employees.view_assigned';
    case AttendanceViewTeam = 'attendance.view_team';
    case AttendanceReview = 'attendance.review';
    case AttendanceCorrectionDecide = 'attendance.correction.decide';
    case ReportsViewTeam = 'reports.view_team';
    case EmployeesManage = 'employees.manage';
    case OrganizationManage = 'organization.manage';
    case SchedulesManage = 'schedules.manage';
    case AttendanceCorrectionsManage = 'attendance.correction.manage';
    case ReportsView = 'reports.view';
    case AuditView = 'audit.view';
    case TenantSettingsManage = 'tenant.settings.manage';
    case TenantConfigurationManage = 'tenant.configuration.manage';
    case UsersManage = 'users.manage';
    case TerminalsManage = 'terminals.manage';
    case PermissionsManage = 'permissions.manage';
    case BrandingManage = 'branding.manage';
    case LocalesManage = 'locales.manage';
    case TimezoneManage = 'timezone.manage';
    case TenantsManage = 'tenants.manage';
    case TenantLifecycleManage = 'tenants.lifecycle';
    case PlatformAdminister = 'platform.administer';
    case PlatformDatabaseExplore = 'platform.database.explore';
}

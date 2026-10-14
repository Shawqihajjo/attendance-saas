import type { ReactNode } from 'react'
import { createBrowserRouter, Navigate } from 'react-router'
import { RequireAccess } from './RequireAccess'
import { AppShell, KioskLayout, ProvidersLayout } from './layouts'
import { HomePage } from '../features/diagnostics/HomePage'
import { KioskPage } from '../features/kiosk/KioskPage'
import { PeoplePage } from '../features/people/PeoplePage'
import { DatabaseExplorerPage } from '../features/platform/DatabaseExplorerPage'
import { PlatformPage } from '../features/platform/PlatformPage'
import { CreateTenantPage, TenantDetailPage } from '../features/platform/TenantDetailPage'
import { LoginPage } from '../features/session/LoginPage'
import { TerminalsPage } from '../features/devices/TerminalsPage'
import { EmployeeDashboard, HrDashboard, ManagerDashboard } from '../features/workspace/Dashboards'
import { AuditPage, ProfilePage, RolesPage, UsersPage } from '../features/workspace/AccountPages'
import { MyAttendancePage, ReportsPage, ReviewedAttendancePage } from '../features/workspace/AttendancePages'
import { MyCorrectionPage, ReviewCorrectionsPage } from '../features/workspace/CorrectionPages'
import { DepartmentsPage, SchedulesPage, TeamsPage } from '../features/workspace/OrganizationPages'
import { SettingsPage } from '../features/workspace/SettingsPage'
import { TenantUsagePage } from '../features/platform/TenantQuotas'

function guard(permission: string, element: ReactNode) {
  return <RequireAccess permission={permission}>{element}</RequireAccess>
}

export const router = createBrowserRouter([
  {
    element: <ProvidersLayout />,
    children: [
      {
        element: <AppShell />,
        children: [
          { path: '/', element: <HomePage /> },
          { path: '/login', element: <LoginPage /> },
          { path: '/app', element: <Navigate to="/me" replace /> },
          { path: '/people', element: guard('employees.manage', <PeoplePage />) },
          { path: '/terminals', element: guard('terminals.manage', <TerminalsPage />) },
          { path: '/me', element: guard('profile.view_own', <EmployeeDashboard />) },
          { path: '/me/attendance', element: guard('attendance.view_own', <MyAttendancePage todayOnly />) },
          { path: '/me/history', element: guard('attendance.view_own', <MyAttendancePage todayOnly={false} />) },
          { path: '/me/profile', element: guard('profile.view_own', <ProfilePage />) },
          { path: '/me/corrections', element: guard('attendance.correction.request', <MyCorrectionPage />) },
          { path: '/team', element: guard('employees.view_assigned', <ManagerDashboard />) },
          { path: '/team/attendance', element: guard('attendance.view_team', <ReviewedAttendancePage titleKey="workspace.teamAttendance" />) },
          { path: '/team/employees', element: guard('employees.view_assigned', <PeoplePage mode="view" />) },
          { path: '/team/review', element: guard('attendance.review', <ReviewedAttendancePage titleKey="workspace.review" />) },
          { path: '/team/corrections', element: guard('attendance.correction.decide', <ReviewCorrectionsPage />) },
          { path: '/team/reports', element: guard('reports.view_team', <ReportsPage titleKey="workspace.teamReports" />) },
          { path: '/hr', element: guard('employees.manage', <HrDashboard />) },
          { path: '/hr/employees', element: guard('employees.manage', <PeoplePage />) },
          { path: '/hr/departments', element: guard('organization.manage', <DepartmentsPage />) },
          { path: '/hr/teams', element: guard('organization.manage', <TeamsPage />) },
          { path: '/hr/schedules', element: guard('schedules.manage', <SchedulesPage />) },
          { path: '/hr/attendance', element: guard('employees.manage', <ReviewedAttendancePage titleKey="workspace.hrAttendance" />) },
          { path: '/hr/corrections', element: guard('attendance.correction.manage', <ReviewCorrectionsPage />) },
          { path: '/hr/reports', element: guard('reports.view', <ReportsPage titleKey="workspace.hrReports" />) },
          { path: '/hr/audit', element: guard('audit.view', <AuditPage />) },
          { path: '/settings', element: guard('tenant.settings.manage', <SettingsPage section="settings" titleKey="workspace.tenantSettings" />) },
          { path: '/settings/usage', element: guard('tenant.configuration.manage', <TenantUsagePage />) },
          { path: '/settings/branding', element: guard('branding.manage', <SettingsPage section="branding" titleKey="workspace.branding" />) },
          { path: '/settings/languages', element: guard('locales.manage', <SettingsPage section="languages" titleKey="workspace.languages" />) },
          { path: '/settings/timezone', element: guard('timezone.manage', <SettingsPage section="timezone" titleKey="workspace.timezone" />) },
          { path: '/settings/users', element: guard('users.manage', <UsersPage />) },
          { path: '/settings/roles', element: guard('permissions.manage', <RolesPage />) },
          { path: '/settings/terminals', element: guard('terminals.manage', <TerminalsPage />) },
          { path: '/platform', element: guard('tenants.manage', <PlatformPage />) },
          { path: '/platform/databases', element: guard('platform.database.explore', <DatabaseExplorerPage />) },
          { path: '/platform/new', element: guard('tenants.lifecycle', <CreateTenantPage />) },
          { path: '/platform/:slug', element: guard('tenants.manage', <TenantDetailPage />) },
        ],
      },
      {
        element: <KioskLayout />,
        children: [{ path: '/kiosk', element: <KioskPage /> }],
      },
    ],
  },
])

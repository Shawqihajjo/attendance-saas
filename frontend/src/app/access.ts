export type WorkspaceArea = 'employee' | 'manager' | 'hr' | 'tenant' | 'platform'

export type WorkspaceRoute = {
  to: string
  labelKey: string
  area: WorkspaceArea
  permission: string
}

export const workspaceRoutes: WorkspaceRoute[] = [
  { to: '/me', labelKey: 'nav.myDashboard', area: 'employee', permission: 'profile.view_own' },
  { to: '/me/attendance', labelKey: 'nav.myAttendance', area: 'employee', permission: 'attendance.view_own' },
  { to: '/me/history', labelKey: 'nav.myHistory', area: 'employee', permission: 'attendance.view_own' },
  { to: '/me/profile', labelKey: 'nav.myProfile', area: 'employee', permission: 'profile.view_own' },
  { to: '/me/corrections', labelKey: 'nav.myCorrection', area: 'employee', permission: 'attendance.correction.request' },
  { to: '/team', labelKey: 'nav.teamDashboard', area: 'manager', permission: 'employees.view_assigned' },
  { to: '/team/attendance', labelKey: 'nav.teamAttendance', area: 'manager', permission: 'attendance.view_team' },
  { to: '/team/employees', labelKey: 'nav.teamEmployees', area: 'manager', permission: 'employees.view_assigned' },
  { to: '/team/review', labelKey: 'nav.attendanceReview', area: 'manager', permission: 'attendance.review' },
  { to: '/team/corrections', labelKey: 'nav.correctionApprovals', area: 'manager', permission: 'attendance.correction.decide' },
  { to: '/team/reports', labelKey: 'nav.teamReports', area: 'manager', permission: 'reports.view_team' },
  { to: '/hr', labelKey: 'nav.hrDashboard', area: 'hr', permission: 'employees.manage' },
  { to: '/hr/employees', labelKey: 'nav.hrEmployees', area: 'hr', permission: 'employees.manage' },
  { to: '/hr/departments', labelKey: 'nav.departments', area: 'hr', permission: 'organization.manage' },
  { to: '/hr/teams', labelKey: 'nav.teams', area: 'hr', permission: 'organization.manage' },
  { to: '/hr/schedules', labelKey: 'nav.schedules', area: 'hr', permission: 'schedules.manage' },
  { to: '/hr/attendance', labelKey: 'nav.hrAttendance', area: 'hr', permission: 'employees.manage' },
  { to: '/hr/corrections', labelKey: 'nav.hrCorrections', area: 'hr', permission: 'attendance.correction.manage' },
  { to: '/hr/reports', labelKey: 'nav.hrReports', area: 'hr', permission: 'reports.view' },
  { to: '/hr/audit', labelKey: 'nav.audit', area: 'hr', permission: 'audit.view' },
  { to: '/settings', labelKey: 'nav.tenantSettings', area: 'tenant', permission: 'tenant.settings.manage' },
  { to: '/settings/usage', labelKey: 'nav.limitsUsage', area: 'tenant', permission: 'tenant.configuration.manage' },
  { to: '/settings/branding', labelKey: 'nav.branding', area: 'tenant', permission: 'branding.manage' },
  { to: '/settings/languages', labelKey: 'nav.languages', area: 'tenant', permission: 'locales.manage' },
  { to: '/settings/timezone', labelKey: 'nav.timezone', area: 'tenant', permission: 'timezone.manage' },
  { to: '/settings/users', labelKey: 'nav.users', area: 'tenant', permission: 'users.manage' },
  { to: '/settings/roles', labelKey: 'nav.roles', area: 'tenant', permission: 'permissions.manage' },
  { to: '/settings/terminals', labelKey: 'nav.terminals', area: 'tenant', permission: 'terminals.manage' },
  { to: '/platform', labelKey: 'nav.platformTenants', area: 'platform', permission: 'tenants.manage' },
  { to: '/platform/databases', labelKey: 'nav.databaseExplorer', area: 'platform', permission: 'platform.database.explore' },
  { to: '/', labelKey: 'nav.home', area: 'platform', permission: 'platform.administer' },
]

export function routesFor(permissions: string[], area?: WorkspaceArea): WorkspaceRoute[] {
  return workspaceRoutes.filter((route) => permissions.includes(route.permission) && (area === undefined || route.area === area))
}

export function landingPath(permissions: string[], kind: 'platform' | 'tenant' | null): string {
  if (kind === 'platform' || permissions.includes('tenants.manage')) {
    return '/platform'
  }
  const first = routesFor(permissions).find((route) => route.to !== '/')
  return first?.to ?? '/'
}

export function safeNextPath(value: string | null): string | null {
  if (value === null || !value.startsWith('/') || value.startsWith('//') || value.startsWith('/kiosk')) {
    return null
  }

  return value
}

import { landingPath, routesFor, safeNextPath } from './access'

describe('workspace access', () => {
  it('lists only routes the server permissions include', () => {
    const routes = routesFor(['profile.view_own', 'attendance.view_own']).map((route) => route.to)
    expect(routes).toEqual(['/me', '/me/attendance', '/me/history', '/me/profile'])
    expect(routesFor(['tenants.manage'], 'platform').map((route) => route.to)).toEqual(['/platform'])
    expect(routesFor(['platform.database.explore'], 'platform').map((route) => route.to)).toEqual(['/platform/databases'])
    expect(routesFor(['tenants.manage', 'tenants.lifecycle'], 'platform').map((route) => route.to).includes('/platform/databases')).toBe(false)
  })

  it('gives a manager the team area and keeps HR routes behind HR permissions', () => {
    const manager = routesFor([
      'employees.view_assigned',
      'attendance.view_team',
      'attendance.review',
      'attendance.correction.decide',
      'reports.view_team',
    ]).map((route) => route.to)
    const hr = routesFor(['employees.manage', 'reports.view', 'audit.view']).map((route) => route.to)

    expect(manager).toEqual([
      '/team',
      '/team/attendance',
      '/team/employees',
      '/team/review',
      '/team/corrections',
      '/team/reports',
    ])
    expect(hr).toEqual(['/hr', '/hr/employees', '/hr/attendance', '/hr/reports', '/hr/audit'])
    expect(manager.includes('/hr/employees')).toBe(false)
    expect(routesFor(['tenant.settings.manage']).map((route) => route.to).includes('/settings/usage')).toBe(false)
    expect(routesFor(['tenant.configuration.manage']).map((route) => route.to)).toEqual(['/settings/usage'])
    expect(routesFor(['profile.view_own']).map((route) => route.to).includes('/team')).toBe(false)
  })

  it('sends a platform operator to tenant management and a tenant user to the first permitted page', () => {
    expect(landingPath(['tenants.manage'], 'platform')).toBe('/platform')
    expect(landingPath(['profile.view_own'], 'tenant')).toBe('/me')
    expect(landingPath([], 'tenant')).toBe('/')
  })

  it('accepts an in-app return path and drops an external or kiosk path', () => {
    expect(safeNextPath('/hr/audit')).toBe('/hr/audit')
    expect(safeNextPath('https://example.test')).toBeNull()
    expect(safeNextPath('//example.test')).toBeNull()
    expect(safeNextPath('/kiosk')).toBeNull()
    expect(safeNextPath(null)).toBeNull()
  })
})

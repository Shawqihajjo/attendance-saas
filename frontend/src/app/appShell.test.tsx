import { configureStore } from '@reduxjs/toolkit'
import { fireEvent, render, screen } from '@testing-library/react'
import type { ReactNode } from 'react'
import { Provider } from 'react-redux'
import { MemoryRouter } from 'react-router'
import { beforeEach, vi } from 'vitest'
import i18n from '../shared/i18n'
import { sessionReducer } from '../features/session/sessionSlice'
import { AppShell } from './layouts'

vi.mock('../features/branding/brandingApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../features/branding/brandingApi')>()
  return {
    ...actual,
    useGetBrandingQuery: () => ({
      data: {
        data: {
          companyName: 'Nordic Attendance Holdings International AB',
          displayName: 'Nordic',
          logoUrl: null,
          supportedLanguages: ['en', 'sv'],
          defaultLanguage: 'en',
        },
      },
    }),
  }
})

vi.mock('../features/session/sessionApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../features/session/sessionApi')>()
  return {
    ...actual,
    useGetSessionQuery: () => ({ data: undefined }),
    useLogoutMutation: () => [vi.fn(), { isLoading: false }],
  }
})

beforeEach(async () => {
  window.matchMedia = (query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: () => undefined,
    removeListener: () => undefined,
    addEventListener: () => undefined,
    removeEventListener: () => undefined,
    dispatchEvent: () => false,
  })
  await i18n.changeLanguage('en')
})

function renderShell(permissions: string[], path = '/me', kind: 'tenant' | 'platform' = 'tenant') {
  const store = configureStore({
    reducer: { session: sessionReducer },
    preloadedState: {
      session: {
        status: 'authenticated' as const,
        roles: [],
        permissions,
        displayName: 'Ada Nordstrom',
        kind,
      },
    },
  })
  function Wrap({ children }: { children: ReactNode }) {
    return (
      <Provider store={store}>
        <MemoryRouter initialEntries={[path]}>{children}</MemoryRouter>
      </Provider>
    )
  }
  return render(<AppShell />, { wrapper: Wrap })
}

describe('application navigation', () => {
  it('shows an employee their own links and keeps other areas hidden', () => {
    renderShell(['profile.view_own', 'attendance.view_own', 'attendance.correction.request'])

    expect(screen.getByRole('link', { name: 'Dashboard' }).className).toContain('active')
    expect(screen.getByRole('link', { name: 'My attendance' }).className).not.toContain('active')
    expect(screen.getByRole('link', { name: 'Correction request' })).toBeTruthy()
    expect(screen.queryByRole('link', { name: 'Team dashboard' })).toBeNull()
    expect(screen.queryByRole('link', { name: 'HR dashboard' })).toBeNull()
    expect(screen.queryByRole('link', { name: 'Tenant settings' })).toBeNull()
    expect(screen.queryByRole('link', { name: 'Limits & Usage' })).toBeNull()
    expect(screen.queryByRole('link', { name: 'Tenant management' })).toBeNull()
    expect(screen.getByText('Signed in as Ada Nordstrom').textContent).toContain('Ada Nordstrom')
    expect(screen.getByText('Nordic Attendance Holdings International AB').getAttribute('title')).toBe('Nordic Attendance Holdings International AB')
  })

  it('shows a chief the team area without HR or platform links', () => {
    renderShell([
      'employees.view_assigned',
      'attendance.view_team',
      'attendance.review',
      'attendance.correction.decide',
      'reports.view_team',
    ], '/team')

    expect(screen.getByRole('link', { name: 'Team dashboard' }).className).toContain('active')
    expect(screen.getByRole('link', { name: 'Correction approvals' })).toBeTruthy()
    expect(screen.queryByRole('link', { name: 'HR dashboard' })).toBeNull()
    expect(screen.queryByRole('link', { name: 'Tenant management' })).toBeNull()
  })

  it('shows HR the people area and a tenant administrator the settings area', () => {
    const { unmount } = renderShell([
      'employees.manage',
      'organization.manage',
      'schedules.manage',
      'attendance.correction.manage',
      'reports.view',
      'audit.view',
      'tenant.settings.manage',
    ], '/hr')
    expect(screen.getByRole('link', { name: 'HR dashboard' })).toBeTruthy()
    expect(screen.getByRole('link', { name: 'Departments' })).toBeTruthy()
    expect(screen.getByRole('link', { name: 'Tenant settings' })).toBeTruthy()
    expect(screen.queryByRole('link', { name: 'Users' })).toBeNull()
    expect(screen.queryByRole('link', { name: 'Limits & Usage' })).toBeNull()
    expect(screen.queryByRole('link', { name: 'Tenant management' })).toBeNull()
    unmount()

    renderShell([
      'tenant.configuration.manage',
      'users.manage',
      'terminals.manage',
      'branding.manage',
      'locales.manage',
      'timezone.manage',
      'permissions.manage',
    ], '/settings/usage')
    expect(screen.getByRole('link', { name: 'Limits & Usage' }).className).toContain('active')
    expect(screen.getByRole('link', { name: 'Users' })).toBeTruthy()
    expect(screen.getByRole('link', { name: 'Terminals' })).toBeTruthy()
    expect(screen.queryByRole('link', { name: 'Tenant management' })).toBeNull()
  })

  it('shows a platform administrator tenant management and diagnostics', () => {
    renderShell(['tenants.manage', 'platform.administer'], '/platform', 'platform')

    expect(screen.getByRole('link', { name: 'Tenant management' }).className).toContain('active')
    expect(screen.getByRole('link', { name: 'Diagnostics' })).toBeTruthy()
    expect(screen.queryByRole('link', { name: 'Database Explorer' })).toBeNull()
    expect(screen.queryByRole('link', { name: 'Dashboard' })).toBeNull()
    expect(screen.queryByRole('link', { name: 'Limits & Usage' })).toBeNull()
  })

  it('shows Database Explorer only when the platform permission is present', () => {
    const { unmount } = renderShell(['tenants.manage', 'platform.database.explore'], '/platform/databases', 'platform')
    expect(screen.getByRole('link', { name: 'Database Explorer' }).className).toContain('active')
    unmount()

    renderShell(['tenants.manage', 'platform.administer'], '/platform', 'platform')
    expect(screen.queryByRole('link', { name: 'Database Explorer' })).toBeNull()
  })

  it('opens from the menu button, closes on Escape, backdrop, and navigation', () => {
    renderShell(['profile.view_own'])
    const menu = screen.getByRole('button', { name: 'Menu' })
    expect(menu.getAttribute('aria-expanded')).toBe('false')
    expect(document.querySelector('.shell')?.className).not.toContain('nav-open')

    fireEvent.click(menu)
    expect(menu.getAttribute('aria-expanded')).toBe('true')
    expect(document.querySelector('.shell')?.className).toContain('nav-open')
    expect(document.querySelector('main')?.hasAttribute('inert')).toBe(true)

    fireEvent.keyDown(document, { key: 'Escape' })
    expect(screen.getByRole('button', { name: 'Menu' }).getAttribute('aria-expanded')).toBe('false')
    expect(document.querySelector('main')?.hasAttribute('inert')).toBe(false)

    fireEvent.click(screen.getByRole('button', { name: 'Menu' }))
    const backdrop = document.querySelector('.nav-backdrop')
    expect(backdrop).toBeTruthy()
    fireEvent.click(backdrop as Element)
    expect(screen.getByRole('button', { name: 'Menu' }).getAttribute('aria-expanded')).toBe('false')

    fireEvent.click(screen.getByRole('button', { name: 'Menu' }))
    fireEvent.click(screen.getByRole('link', { name: 'Kiosk' }))
    expect(screen.getByRole('button', { name: 'Menu' }).getAttribute('aria-expanded')).toBe('false')
  })

  it('renders the Swedish navigation labels', async () => {
    await i18n.changeLanguage('sv')
    renderShell(['tenant.settings.manage', 'tenant.configuration.manage'], '/settings')

    expect(screen.getByRole('link', { name: 'Företagsinställningar' })).toBeTruthy()
    expect(screen.getByRole('link', { name: 'Gränser och användning' })).toBeTruthy()
    expect(screen.getByRole('button', { name: 'Meny' })).toBeTruthy()
    expect(screen.getByText('Inloggad som Ada Nordstrom').textContent).toContain('Ada Nordstrom')
  })
})

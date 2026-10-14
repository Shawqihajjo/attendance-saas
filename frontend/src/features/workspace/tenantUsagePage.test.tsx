import { configureStore } from '@reduxjs/toolkit'
import { render, screen } from '@testing-library/react'
import type { ReactNode } from 'react'
import { Provider } from 'react-redux'
import { MemoryRouter } from 'react-router'
import { vi } from 'vitest'
import { RequireAccess } from '../../app/RequireAccess'
import i18n from '../../shared/i18n'
import { sessionReducer } from '../session/sessionSlice'
import { TenantUsagePage } from '../platform/TenantQuotas'

const usage = vi.hoisted(() => ({
  current: {
    isLoading: false,
    error: undefined as unknown,
    data: undefined as unknown,
  },
}))

vi.mock('./workspaceApi', () => ({
  useGetTenantUsageQuery: () => usage.current,
}))

const report = {
  maxUsers: 100,
  maxEmployees: 250,
  maxActiveDevices: 10,
  usage: {
    users: { used: 80, limit: 100, remaining: 20, level: 'warning', overLimit: false },
    employees: { used: 250, limit: 250, remaining: 0, level: 'reached', overLimit: false },
    devices: { used: 12, limit: 10, remaining: 0, level: 'over', overLimit: true },
  },
}

function renderPage(permissions: string[]) {
  const store = configureStore({
    reducer: { session: sessionReducer },
    preloadedState: {
      session: {
        status: 'authenticated' as const,
        roles: [],
        permissions,
        displayName: 'Ada',
        kind: 'tenant' as const,
      },
    },
  })

  return render(
    <Provider store={store}>
      <MemoryRouter initialEntries={['/settings/usage']}>
        <RequireAccess permission="tenant.configuration.manage">
          <TenantUsagePage />
        </RequireAccess>
      </MemoryRouter>
    </Provider>,
  )
}

describe('tenant limits and usage', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en')
    usage.current = { isLoading: false, error: undefined, data: { data: report } }
  })

  it('hides the page from an operator who cannot manage tenant configuration', () => {
    renderPage(['tenant.settings.manage', 'employees.manage'])

    expect(screen.getByRole('heading', { name: 'This page is not available' }).textContent).toBe('This page is not available')
    expect(screen.queryByText('80 of 100 users used')).toBeNull()
    expect(screen.queryByRole('button', { name: 'Edit limits' })).toBeNull()
  })

  it('shows the signed-in tenant usage and explains a full device limit', () => {
    renderPage(['tenant.configuration.manage'])

    expect(screen.getByRole('heading', { name: 'Limits & Usage' }).textContent).toBe('Limits & Usage')
    expect(screen.getByText('80 of 100 users used').textContent).toContain('80 of 100')
    expect(screen.getByText('250 of 250 employees used').textContent).toContain('250 of 250')
    expect(screen.getByText('12 of 10 active devices used').textContent).toContain('12 of 10')
    expect(screen.getByText('Limit exceeded: reduce usage or increase the limit').textContent).toContain('Limit exceeded')
    expect(screen.getByText('User accounts are approaching the limit. Contact the platform administrator before more accounts are needed.').textContent).toContain('platform administrator')
    expect(screen.getByText('The employee limit has been reached. Contact the platform administrator before more employees can be added.').textContent).toContain('employee limit')
    expect(screen.getByText('Active device capacity is full. Deactivate an existing terminal, or contact the platform administrator to increase the limit.').textContent).toContain('Deactivate')
    expect(screen.queryByRole('button', { name: 'Edit limits' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Increase limit' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Save limits' })).toBeNull()
  })

  it('does not present a zero limit that has been exceeded as unlimited', () => {
    usage.current = {
      isLoading: false,
      error: undefined,
      data: {
        data: {
          maxUsers: null,
          maxEmployees: null,
          maxActiveDevices: 0,
          usage: {
            users: { used: 1, limit: null, remaining: null, level: 'ok', overLimit: false },
            employees: { used: 1, limit: null, remaining: null, level: 'ok', overLimit: false },
            devices: { used: 4, limit: 0, remaining: 0, level: 'over', overLimit: true },
          },
        },
      },
    }
    renderPage(['tenant.configuration.manage'])

    const devices = screen.getByRole('heading', { name: 'Attendance devices', level: 3 }).closest('article')
    expect(devices?.textContent).toContain('Limit exceeded')
    expect(devices?.textContent).toContain('Maximum allowed')
    expect(devices?.textContent).not.toContain('None')
  })

  it('shows loading and a failed usage request without limit controls', () => {
    usage.current = { isLoading: true, error: undefined, data: undefined }
    const view = renderPage(['tenant.configuration.manage'])
    expect(screen.getByText('Loading').textContent).toContain('Loading')

    usage.current = { isLoading: false, error: { status: 'FETCH_ERROR' }, data: undefined }
    view.rerender(page(['tenant.configuration.manage'], (
      <RequireAccess permission="tenant.configuration.manage">
        <TenantUsagePage />
      </RequireAccess>
    )))
    expect(screen.getByRole('alert').textContent).toContain('The service could not be reached.')
    expect(screen.queryByRole('button', { name: 'Edit limits' })).toBeNull()
  })
})

function page(permissions: string[], ui: ReactNode) {
  return (
    <Provider store={configureStore({
      reducer: { session: sessionReducer },
      preloadedState: {
        session: {
          status: 'authenticated' as const,
          roles: [],
          permissions,
          displayName: 'Ada',
          kind: 'tenant' as const,
        },
      },
    })}
    >
      <MemoryRouter>{ui}</MemoryRouter>
    </Provider>
  )
}

import { configureStore } from '@reduxjs/toolkit'
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import type { ReactNode } from 'react'
import { Provider } from 'react-redux'
import { MemoryRouter, Route, Routes } from 'react-router'
import { vi } from 'vitest'
import { RequireAccess } from '../../app/RequireAccess'
import i18n from '../../shared/i18n'
import { catalogs } from '../../shared/i18n/catalog'
import { brandingReducer } from '../branding/brandingSlice'
import { sessionReducer } from '../session/sessionSlice'
import { PlatformPage } from './PlatformPage'
import { TenantDetailPage } from './TenantDetailPage'
import { TenantForm } from './TenantForm'
import type { TenantSummary } from './platformApi'

const api = vi.hoisted(() => ({
  listArgs: undefined as unknown,
  list: {
    isLoading: false,
    isFetching: false,
    error: undefined as unknown,
    data: undefined as unknown,
    refetch: vi.fn(),
  },
  audit: {
    isLoading: false,
    error: undefined as unknown,
    data: { data: [], meta: {} },
  },
  suspend: vi.fn(),
  drop: vi.fn(),
  create: vi.fn(),
  updateQuotas: vi.fn(),
  quotas: {
    isLoading: false,
    error: undefined as unknown,
    data: undefined as unknown,
  },
}))

vi.mock('./platformApi', () => ({
  useGetTenantsQuery: (args: unknown) => {
    api.listArgs = args
    return api.list
  },
  useGetTenantQuery: (slug: string) => {
    const rows = (api.list.data as { data?: Array<{ slug: string }> } | undefined)?.data ?? []
    const found = rows.find((item) => item.slug === slug)
    if (api.list.isLoading) {
      return { isLoading: true, error: undefined, data: undefined }
    }
    if (api.list.error) {
      return { isLoading: false, error: api.list.error, data: undefined }
    }
    if (!found) {
      return { isLoading: false, error: undefined, data: undefined }
    }
    return { isLoading: false, error: undefined, data: { data: found, meta: {} } }
  },
  useGetPlatformAuditQuery: () => api.audit,
  useGetTenantQuotasQuery: () => api.quotas,
  useUpdateTenantQuotasMutation: () => [api.updateQuotas, { isLoading: false }],
  useCreateTenantMutation: () => [api.create, { isLoading: false }],
  useUpdateTenantMutation: () => [vi.fn(), { isLoading: false }],
  useActivateTenantMutation: () => [vi.fn(), { isLoading: false }],
  useSuspendTenantMutation: () => [api.suspend, { isLoading: false }],
  useReactivateTenantMutation: () => [vi.fn(), { isLoading: false }],
  useArchiveTenantMutation: () => [vi.fn(), { isLoading: false }],
  useDropTenantDatabaseMutation: () => [api.drop, { isLoading: false }],
  useCheckTenantDatabaseMutation: () => [vi.fn(), { isLoading: false }],
}))

const tenant: TenantSummary = {
  id: 'tenant-1',
  legalName: 'Northwind AB',
  slug: 'north',
  hostname: 'north.localhost',
  status: 'active',
  defaultLocale: 'en',
  enabledLocales: ['en', 'sv'],
  timezone: 'Europe/Stockholm',
  createdAt: '2026-10-12T08:00:00Z',
  provisioningStatus: 'ready',
  lastActivityAt: null,
}

function renderAt(path: string, permissions: string[], ui: ReactNode) {
  const store = configureStore({
    reducer: { session: sessionReducer, branding: brandingReducer },
    preloadedState: {
      session: {
        status: 'authenticated' as const,
        roles: [],
        permissions,
        displayName: 'Operator',
        kind: permissions.includes('tenants.manage') ? 'platform' as const : 'tenant' as const,
      },
    },
  })
  return render(
    <Provider store={store}>
      <MemoryRouter initialEntries={[path]}>
        {ui}
      </MemoryRouter>
    </Provider>,
  )
}

describe('tenant management screens', () => {
  beforeEach(async () => {
    await i18n.changeLanguage('en')
    api.list = { isLoading: false, isFetching: false, error: undefined, data: undefined, refetch: vi.fn() }
    api.suspend.mockReset()
    api.drop.mockReset()
    api.create.mockReset()
    api.updateQuotas.mockReset()
    api.quotas = {
      isLoading: false,
      error: undefined,
      data: {
        data: {
          maxUsers: null,
          maxEmployees: 1,
          maxActiveDevices: null,
          usage: {
            users: { used: 1, limit: null, remaining: null, level: 'ok', overLimit: false },
            employees: { used: 1, limit: 1, remaining: 0, level: 'reached', overLimit: false },
            devices: { used: 0, limit: null, remaining: null, level: 'ok', overLimit: false },
          },
        },
      },
    }
  })

  it('keeps the English and Swedish catalogs aligned', () => {
    expect(flat(catalogs.en.platform)).toEqual(flat(catalogs.sv.platform))
  })

  it('refuses the page when the session cannot manage tenants', () => {
    renderAt('/platform', ['profile.view_own'], (
      <RequireAccess permission="tenants.manage">
        <PlatformPage />
      </RequireAccess>
    ))

    expect(screen.getByRole('heading', { name: 'This page is not available' }).textContent).toBe('This page is not available')
    expect(screen.queryByRole('button', { name: 'Suspend' })).toBeNull()
  })

  it('shows loading, failure, and an empty list', () => {
    api.list.isLoading = true
    const view = renderAt('/platform', ['tenants.manage'], <PlatformPage />)
    expect(screen.getByText('Loading').textContent).toContain('Loading')

    api.list = { isLoading: false, isFetching: false, error: { status: 'FETCH_ERROR' }, data: undefined, refetch: vi.fn() }
    view.rerender(page('/platform', ['tenants.manage'], <PlatformPage />))
    expect(screen.getByRole('alert').textContent).toContain('The service could not be reached.')

    api.list = {
      isLoading: false,
      isFetching: false,
      error: undefined,
      data: { data: [], meta: { page: { limit: 20, offset: 0, total: 0 } } },
      refetch: vi.fn(),
    }
    view.rerender(page('/platform', ['tenants.manage'], <PlatformPage />))
    expect(screen.getByText('No tenants match this search.').textContent).toContain('No tenants match')
  })

  it('sends search, status, and the next page to the tenant query', () => {
    api.list.data = { data: [tenant], meta: { page: { limit: 20, offset: 0, total: 40 } } }
    renderAt('/platform', ['tenants.manage'], <PlatformPage />)

    fireEvent.change(screen.getByLabelText('Search'), { target: { value: ' north ' } })
    fireEvent.click(screen.getByRole('button', { name: 'Search' }))
    expect(api.listArgs).toMatchObject({ q: 'north', status: '', offset: 0 })

    fireEvent.change(screen.getByLabelText('Status'), { target: { value: 'suspended' } })
    expect(api.listArgs).toMatchObject({ q: 'north', status: 'suspended', offset: 0 })

    fireEvent.click(screen.getByRole('button', { name: 'Next' }))
    expect(api.listArgs).toMatchObject({ offset: 20, limit: 20 })
  })

  it('asks before suspending and waits for confirmation', async () => {
    api.list.data = { data: [tenant], meta: { page: { limit: 20, offset: 0, total: 1 } } }
    api.suspend.mockReturnValue({ unwrap: () => Promise.resolve({ data: { ...tenant, status: 'suspended' } }) })
    renderAt('/platform', ['tenants.manage', 'tenants.lifecycle'], <PlatformPage />)

    fireEvent.click(screen.getAllByRole('button', { name: 'Suspend' })[0])
    const dialog = screen.getByRole('dialog')
    expect(dialog.textContent).toContain('will not be able to sign in or record attendance')
    expect(api.suspend).not.toHaveBeenCalled()

    fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }))
    expect(api.suspend).not.toHaveBeenCalled()

    fireEvent.click(screen.getAllByRole('button', { name: 'Suspend' })[0])
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Suspend' }))
    await waitFor(() => expect(api.suspend).toHaveBeenCalledWith({ slug: 'north' }))
    expect(screen.getByText('The tenant is suspended. Sign-in and attendance are stopped.').textContent).toContain('suspended')
  })

  it('hides lifecycle buttons from an operator who can only view tenants', () => {
    api.list.data = { data: [tenant], meta: { page: { limit: 20, offset: 0, total: 1 } } }
    renderAt('/platform', ['tenants.manage'], <PlatformPage />)

    expect(screen.getAllByText('Northwind AB').length).toBeGreaterThan(0)
    expect(screen.queryByRole('button', { name: 'Suspend' })).toBeNull()
    expect(screen.queryByRole('link', { name: 'Add tenant' })).toBeNull()
  })

  it('blocks an invalid create and shows a duplicate identifier from the server', async () => {
    api.create.mockReturnValue({
      unwrap: () => Promise.reject({
        status: 409,
        data: { code: 'http_error', title: 'Request error', status: 409, detail: 'The tenant slug or hostname is already registered.' },
      }),
    })
    renderAt('/platform/new', ['tenants.lifecycle'], <TenantForm mode="create" />)

    fireEvent.click(screen.getByRole('button', { name: 'Create tenant' }))
    expect(screen.getByRole('alert').textContent).toContain('Enter between 1 and 200 characters.')
    expect(api.create).not.toHaveBeenCalled()

    fireEvent.change(screen.getByLabelText('Company name'), { target: { value: 'Northwind AB' } })
    fireEvent.change(screen.getByLabelText('Tenant identifier'), { target: { value: 'north' } })
    fireEvent.click(screen.getByRole('button', { name: 'Create tenant' }))
    expect((await screen.findByText('That identifier or domain is already registered.')).textContent).toContain('already registered')
    expect(screen.queryByText('The tenant is active.')).toBeNull()

    api.create.mockClear()
    let release: (() => void) | undefined
    api.create.mockReturnValue({ unwrap: () => new Promise<void>((resolve) => { release = resolve }) })
    fireEvent.click(screen.getByRole('button', { name: 'Create tenant' }))
    fireEvent.click(screen.getByRole('button', { name: 'Create tenant' }))
    expect(api.create).toHaveBeenCalledTimes(1)
    await act(async () => { release?.() })
  })

  it('shows server usage on the list and reports a quota request failure', () => {
    api.list.data = { data: [tenant], meta: { page: { limit: 20, offset: 0, total: 1 } } }
    const view = renderAt('/platform', ['tenants.manage'], <PlatformPage />)

    expect(screen.getAllByText('1 of 1 employees used', { exact: false }).length).toBe(2)
    expect(screen.getByText('Employees: 1 of 1 employees used').textContent).toContain('Employees:')
    expect(screen.getAllByText('Limit reached').length).toBeGreaterThan(0)
    expect(screen.queryByRole('button', { name: 'Edit limits' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Increase limit' })).toBeNull()

    api.quotas = { isLoading: false, error: { status: 'FETCH_ERROR' }, data: undefined }
    view.rerender(page('/platform', ['tenants.manage'], <PlatformPage />))
    expect(screen.getAllByText('The service could not be reached.').length).toBeGreaterThan(0)
    expect(screen.getAllByText('Northwind AB').length).toBeGreaterThan(0)
  })

  it('shows each quota card and hides editing from a view-only operator', () => {
    api.list.data = { data: [tenant], meta: { page: { limit: 20, offset: 0, total: 1 } } }
    renderAt('/platform/north', ['tenants.manage'], (
      <Routes>
        <Route path="/platform/:slug" element={<TenantDetailPage />} />
      </Routes>
    ))

    expect(screen.getByRole('heading', { name: 'Limits & Usage' }).textContent).toBe('Limits & Usage')
    expect(screen.getByRole('heading', { name: 'Users', level: 3 }).textContent).toBe('Users')
    expect(screen.getByRole('heading', { name: 'Employees', level: 3 }).textContent).toBe('Employees')
    expect(screen.getByRole('heading', { name: 'Attendance devices', level: 3 }).textContent).toBe('Attendance devices')
    expect(screen.getByText('1 of 1 employees used').textContent).toContain('1 of 1')
    expect(screen.getByText('1 used, no limit').textContent).toContain('no limit')
    expect(screen.getByText('0 used, no limit').textContent).toContain('no limit')
    expect(screen.getAllByText('Current usage').length).toBe(3)
    expect(screen.getAllByText('Maximum allowed').length).toBe(3)
    expect(screen.getAllByText('Remaining capacity').length).toBe(2)
    expect(screen.getByText('Remaining active device capacity').textContent).toContain('Remaining')
    expect(screen.getAllByText('Usage percentage').length).toBe(3)
    expect(screen.getByText('100%').textContent).toBe('100%')
    expect(screen.queryByRole('button', { name: 'Edit limits' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Increase limit' })).toBeNull()
  })

  it('warns from the server levels and requires confirmation before a lower limit', async () => {
    api.quotas = {
      isLoading: false,
      error: undefined,
      data: {
        data: {
          maxUsers: 100,
          maxEmployees: 250,
          maxActiveDevices: 10,
          usage: {
            users: { used: 72, limit: 100, remaining: 28, level: 'ok', overLimit: false },
            employees: { used: 200, limit: 250, remaining: 50, level: 'warning', overLimit: false },
            devices: { used: 12, limit: 10, remaining: 0, level: 'over', overLimit: true },
          },
        },
      },
    }
    api.list.data = { data: [tenant], meta: { page: { limit: 20, offset: 0, total: 1 } } }
    api.updateQuotas.mockReturnValue({ unwrap: () => Promise.resolve({ data: api.quotas.data }) })
    renderAt('/platform/north', ['tenants.manage', 'tenants.lifecycle'], (
      <Routes>
        <Route path="/platform/:slug" element={<TenantDetailPage />} />
      </Routes>
    ))

    expect(screen.getByText('72 of 100 users used').textContent).toContain('72 of 100')
    expect(screen.getByText('200 of 250 employees used').textContent).toContain('200 of 250')
    expect(screen.getByText('Approaching limit').textContent).toBe('Approaching limit')
    expect(screen.getByText('12 of 10 active devices used').textContent).toContain('12 of 10')
    expect(screen.getByText('Limit exceeded: reduce usage or increase the limit').textContent).toContain('Limit exceeded')
    expect(screen.getAllByRole('button', { name: 'Increase limit' }).length).toBe(2)

    fireEvent.click(screen.getByRole('button', { name: 'Edit limits' }))
    const employees = screen.getByRole('group', { name: 'Employees' })
    expect(employees.textContent).toContain('Current usage: 200')
    fireEvent.change(within(employees).getByRole('textbox'), { target: { value: '10' } })
    expect(employees.textContent).toContain('This limit is below current usage')
    fireEvent.click(screen.getByRole('button', { name: 'Save limits' }))
    const dialog = screen.getByRole('dialog')
    expect(dialog.textContent).toContain('Existing records stay in place')
    expect(api.updateQuotas).not.toHaveBeenCalled()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Cancel' }))
    expect(screen.queryByRole('dialog')).toBeNull()
    expect(api.updateQuotas).not.toHaveBeenCalled()
    fireEvent.click(screen.getByRole('button', { name: 'Save limits' }))
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Apply lower limit' }))
    await waitFor(() => expect(api.updateQuotas).toHaveBeenCalledWith({
      slug: 'north',
      body: { maxUsers: 100, maxEmployees: 10, maxActiveDevices: 10 },
    }))
  })

  it('shows a quota update failure and blocks an empty finite limit', async () => {
    api.list.data = { data: [tenant], meta: { page: { limit: 20, offset: 0, total: 1 } } }
    api.updateQuotas.mockReturnValue({
      unwrap: () => Promise.reject({ status: 'FETCH_ERROR' }),
    })
    renderAt('/platform/north', ['tenants.manage', 'tenants.lifecycle'], (
      <Routes>
        <Route path="/platform/:slug" element={<TenantDetailPage />} />
      </Routes>
    ))

    fireEvent.click(screen.getByRole('button', { name: 'Edit limits' }))
    const users = screen.getByRole('group', { name: 'Users' })
    fireEvent.click(within(users).getByRole('checkbox', { name: 'Unlimited' }))
    fireEvent.click(screen.getByRole('button', { name: 'Save limits' }))
    expect(screen.getByRole('alert').textContent).toContain('Enter a whole number from 0 to 1000000')
    expect(api.updateQuotas).not.toHaveBeenCalled()

    fireEvent.change(within(users).getByRole('textbox'), { target: { value: '5' } })
    fireEvent.click(screen.getByRole('button', { name: 'Save limits' }))
    expect((await screen.findByText('The service could not be reached.')).textContent).toContain('could not be reached')
    await waitFor(() => expect(api.updateQuotas).toHaveBeenCalledWith({
      slug: 'north',
      body: { maxUsers: 5, maxEmployees: 1, maxActiveDevices: null },
    }))
  })

  it('keeps permanent deletion behind a typed identifier', async () => {
    api.list.data = {
      data: [{ ...tenant, status: 'decommissioned' }],
      meta: { page: { limit: 20, offset: 0, total: 1 } },
    }
    api.drop.mockReturnValue({
      unwrap: () => Promise.resolve({ data: { ...tenant, status: 'decommissioned', provisioningStatus: 'removed' } }),
    })
    renderAt('/platform/north', ['tenants.manage', 'tenants.lifecycle', 'platform.administer'], (
      <Routes>
        <Route path="/platform/:slug" element={<TenantDetailPage />} />
      </Routes>
    ))

    expect(screen.queryByRole('button', { name: 'Suspend' })).toBeNull()
    fireEvent.click(screen.getByRole('button', { name: 'Delete database' }))
    const dialog = screen.getByRole('dialog')
    expect(dialog.textContent).toContain('Suspending or archiving does not do this')
    expect((within(dialog).getByRole('button', { name: 'Delete database' }) as HTMLButtonElement).disabled).toBe(true)
    expect(api.drop).not.toHaveBeenCalled()

    fireEvent.change(within(dialog).getByLabelText('Tenant identifier'), { target: { value: 'north' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Delete database' }))
    await waitFor(() => expect(api.drop).toHaveBeenCalledWith({ slug: 'north', confirmation: 'north' }))
  })
})

function page(path: string, permissions: string[], ui: ReactNode) {
  return (
    <Provider store={configureStore({
      reducer: { session: sessionReducer, branding: brandingReducer },
      preloadedState: {
        session: {
          status: 'authenticated' as const,
          roles: [],
          permissions,
          displayName: 'Operator',
          kind: 'platform' as const,
        },
      },
    })}
    >
      <MemoryRouter initialEntries={[path]}>{ui}</MemoryRouter>
    </Provider>
  )
}

function flat(value: unknown, prefix = ''): string[] {
  if (typeof value !== 'object' || value === null) {
    return [prefix]
  }
  return Object.entries(value).flatMap(([key, child]) => flat(child, prefix === '' ? key : `${prefix}.${key}`))
}

import { configureStore } from '@reduxjs/toolkit'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import type { ReactNode } from 'react'
import { Provider } from 'react-redux'
import { beforeEach, vi } from 'vitest'
import i18n from '../../shared/i18n'
import { brandingReducer } from '../branding/brandingSlice'
import { explorerReducer } from './explorerSlice'
import { DatabaseExplorerPage } from './DatabaseExplorerPage'

const api = vi.hoisted(() => ({
  databases: {
    isLoading: false,
    isFetching: false,
    error: undefined as unknown,
    data: undefined as unknown,
  },
  tablesTarget: undefined as string | undefined,
  rowsArgs: undefined as { target?: string; offset?: number; sort?: string; filters?: unknown; q?: string; columns?: string } | undefined,
  rowsLoading: false,
  rowsError: undefined as unknown,
  hasMore: false,
  detail: {
    isLoading: false,
    error: undefined as unknown,
    data: {
      data: {
        name: 'employees',
        kind: 'table',
        rowEstimate: null,
        columns: [
          { name: 'display_name', type: 'varchar', nullable: false, primaryKey: false },
          { name: 'status', type: 'varchar', nullable: false, primaryKey: false },
          { name: 'created_at', type: 'datetime', nullable: false, primaryKey: false },
        ],
        indexes: [{ name: 'employees_status', unique: false, columns: ['status'] }],
        foreignKeys: [],
      },
    },
  },
  run: vi.fn(),
  query: {
    isLoading: false,
    error: undefined as unknown,
    data: undefined as unknown,
    originalArgs: undefined as { ticket?: number; target?: string } | undefined,
    reset: vi.fn(),
  },
}))

vi.mock('./explorerApi', () => ({
  useGetExplorerDatabasesQuery: () => api.databases,
  useGetExplorerTablesQuery: (target: string, options?: { skip?: boolean }) => {
    api.tablesTarget = options?.skip ? undefined : target
    if (options?.skip) {
      return { isLoading: false, isFetching: false, error: undefined, data: undefined }
    }
    const names = target === 'platform'
      ? [{ name: 'tenants', kind: 'table', rowEstimate: 2 }]
      : [{ name: 'employees', kind: 'table', rowEstimate: null }, { name: 'users', kind: 'table', rowEstimate: null }]
    return { isLoading: false, isFetching: false, error: undefined, data: { data: names } }
  },
  useGetExplorerTableQuery: (_args: unknown, options?: { skip?: boolean }) => (
    options?.skip ? { isLoading: false, error: undefined, data: undefined } : api.detail
  ),
  useGetExplorerRowsQuery: (args: { target?: string; offset?: number; sort?: string; filters?: unknown; q?: string; columns?: string }, options?: { skip?: boolean }) => {
    if (options?.skip) {
      return { isLoading: false, isFetching: false, error: undefined, data: undefined }
    }
    api.rowsArgs = args
    if (api.rowsLoading) {
      return { isLoading: true, isFetching: true, error: undefined, data: undefined }
    }
    if (api.rowsError) {
      return { isLoading: false, isFetching: false, error: api.rowsError, data: undefined }
    }
    const name = args.target === 'south' ? 'South Person' : 'North Explorer'
    return {
      isLoading: false,
      isFetching: false,
      error: undefined,
      data: {
        data: { columns: ['display_name', 'created_at'], rows: [{ display_name: name, created_at: '2026-10-12 08:00:00' }] },
        meta: { page: { limit: 50, offset: args.offset, returned: 1, hasMore: api.hasMore }, durationMs: 4 },
      },
    }
  },
  useRunExplorerQueryMutation: () => [api.run, api.query],
}))

vi.mock('../../shared/theme/useTenantFormat', () => ({
  useTenantFormat: () => ({
    dateTime: (value: string) => (value.startsWith('2026-10-12') ? '12 Oct 2026, 08:00' : value),
    number: (value: number) => String(value),
  }),
}))

const catalog = {
  data: [
    { target: 'platform', label: 'Platform', kind: 'platform', status: null },
    { target: 'north', label: 'Northwind AB', kind: 'tenant', status: 'active' },
    { target: 'south', label: 'Southwind AB', kind: 'tenant', status: 'suspended' },
  ],
  meta: { durationMs: 1 },
}

function renderPage() {
  const store = configureStore({
    reducer: { explorer: explorerReducer, branding: brandingReducer },
  })
  function Wrap({ children }: { children: ReactNode }) {
    return <Provider store={store}>{children}</Provider>
  }
  return render(<DatabaseExplorerPage />, { wrapper: Wrap })
}

beforeEach(async () => {
  await i18n.changeLanguage('en')
  api.databases = { isLoading: false, isFetching: false, error: undefined, data: catalog }
  api.rowsLoading = false
  api.rowsError = undefined
  api.hasMore = false
  api.rowsArgs = undefined
  api.query = { isLoading: false, error: undefined, data: undefined, originalArgs: undefined, reset: vi.fn() }
  api.run = vi.fn((args: { target: string; sql: string; ticket: number }) => {
    api.query = {
      isLoading: false,
      error: undefined,
      originalArgs: args,
      reset: vi.fn(),
      data: {
        data: { columns: ['display_name'], rows: [{ display_name: 'North Explorer' }] },
        meta: { page: { limit: 1000, offset: 0, returned: 1, hasMore: false, truncated: false }, durationMs: 6 },
      },
    }
    return { unwrap: () => Promise.resolve(api.query.data), abort: vi.fn() }
  })
})

describe('database explorer page', () => {
  it('loads a selected table and pages the rows on the server', () => {
    renderPage()
    fireEvent.click(screen.getByRole('button', { name: /Northwind AB/ }))
    fireEvent.click(screen.getByRole('button', { name: /employees/ }))

    expect(screen.getByRole('heading', { name: 'Northwind AB / employees' })).toBeTruthy()
    expect(screen.getAllByText('varchar').length).toBeGreaterThan(0)
    expect(screen.getByText(/employees_status/)).toBeTruthy()
    expect(screen.getByText('North Explorer')).toBeTruthy()
    expect(screen.getByText('12 Oct 2026, 08:00')).toBeTruthy()
    expect(api.rowsArgs?.offset).toBe(0)
    expect(api.rowsArgs?.target).toBe('north')

    api.hasMore = true
    fireEvent.click(screen.getByRole('button', { name: 'display_name' }))
    expect(api.rowsArgs?.sort).toBe('display_name')
    fireEvent.click(screen.getByRole('button', { name: 'Next page' }))
    expect(api.rowsArgs?.offset).toBe(50)
  })

  it('shows loading and error states and drops the previous tenant when the database changes', async () => {
    api.databases = { isLoading: true, isFetching: true, error: undefined, data: undefined }
    const { unmount } = renderPage()
    expect(screen.getByText('Loading')).toBeTruthy()
    unmount()

    api.databases = {
      isLoading: false,
      isFetching: false,
      error: { status: 503, data: { code: 'explorer_unavailable', title: 'Database explorer unavailable', status: 503, detail: 'unavailable' } },
      data: undefined,
    }
    const failed = renderPage()
    expect(screen.getByRole('alert').textContent).toContain('read-only database explorer account')
    failed.unmount()

    api.databases = { isLoading: false, isFetching: false, error: undefined, data: catalog }
    renderPage()
    fireEvent.click(screen.getByRole('button', { name: /Northwind AB/ }))
    fireEvent.click(screen.getByRole('button', { name: /employees/ }))
    expect(screen.getByText('North Explorer')).toBeTruthy()
    fireEvent.click(screen.getByRole('button', { name: /Southwind AB/ }))
    expect(screen.queryByText('North Explorer')).toBeNull()
    expect(screen.getByText('Select a table.')).toBeTruthy()
    expect(api.tablesTarget).toBe('south')

    api.run = vi.fn((args: { ticket: number }) => {
      api.query = {
        isLoading: false,
        data: undefined,
        originalArgs: args,
        reset: vi.fn(),
        error: { status: 422, data: { code: 'query_rejected', title: 'Query rejected', status: 422, detail: 'refused' } },
      }
      return { unwrap: () => Promise.reject(api.query.error) }
    })
    fireEvent.change(screen.getByPlaceholderText('SELECT …'), { target: { value: 'DELETE FROM employees' } })
    fireEvent.click(screen.getByRole('button', { name: 'Run query' }))
    await waitFor(() => expect(screen.getByRole('status').textContent).toContain('Query failed'))
    expect(screen.getByRole('alert').textContent).toContain('read-only query')
    expect(screen.getByText('This query runs on Southwind AB.')).toBeTruthy()
  })

  it('shows success only after the query returns and ignores a second run', async () => {
    let finish: (value: unknown) => void = () => {}
    const abort = vi.fn()
    const payload = {
      data: { columns: ['display_name'], rows: [{ display_name: 'North Explorer' }] },
      meta: { page: { limit: 1000, offset: 0, returned: 1, hasMore: false, truncated: true }, durationMs: 4 },
    }
    api.run = vi.fn((args: { ticket: number }) => {
      api.query = { isLoading: false, error: undefined, data: undefined, originalArgs: args, reset: vi.fn() }
      return {
        abort,
        unwrap: () => new Promise((resolve) => {
          finish = (value: unknown) => {
            api.query = { ...api.query, data: value }
            resolve(value)
          }
        }),
      }
    })
    renderPage()
    fireEvent.click(screen.getByRole('button', { name: /Northwind AB/ }))
    fireEvent.change(screen.getByPlaceholderText('SELECT …'), { target: { value: 'SELECT display_name FROM employees' } })
    fireEvent.click(screen.getByRole('button', { name: 'Run query' }))
    expect(screen.getByRole('status').textContent).toContain('Running query')
    expect(screen.getByRole('status').textContent).not.toContain('Query finished')
    fireEvent.click(screen.getByRole('button', { name: 'Running query' }))
    expect(api.run).toHaveBeenCalledTimes(1)
    finish(payload)
    await waitFor(() => expect(screen.getByRole('status').textContent).toContain('Query finished'))
    expect(screen.getByText('The result was limited to 1000 rows.')).toBeTruthy()
    expect(screen.getByText('North Explorer')).toBeTruthy()

    fireEvent.click(screen.getByRole('button', { name: 'Run query' }))
    expect(screen.getByRole('status').textContent).toContain('Running query')
    fireEvent.click(screen.getByRole('button', { name: 'Cancel query' }))
    expect(abort).toHaveBeenCalled()
    finish(payload)
    await waitFor(() => expect(screen.getByRole('status').textContent).toContain('Query cancelled'))
    expect(screen.getByRole('status').textContent).not.toContain('Query finished')
  })
})

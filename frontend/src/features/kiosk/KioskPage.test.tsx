import { configureStore } from '@reduxjs/toolkit'
import { render, screen } from '@testing-library/react'
import { Provider } from 'react-redux'
import { vi } from 'vitest'
import { baseApi } from '../../shared/api/baseApi'
import i18n from '../../shared/i18n'
import { brandingReducer } from '../branding/brandingSlice'
import { sessionReducer } from '../session/sessionSlice'
import { KioskPage } from './KioskPage'
import { kioskReducer, type KioskState } from './kioskSlice'

vi.mock('../branding/brandingApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../branding/brandingApi')>()
  return {
    ...actual,
    useGetBrandingQuery: () => ({ data: undefined }),
  }
})

vi.mock('../diagnostics/diagnosticsApi', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../diagnostics/diagnosticsApi')>()
  return {
    ...actual,
    useGetHealthQuery: () => ({ data: { data: { time: '2026-10-08T07:00:00Z' } } }),
    useGetReadinessQuery: () => ({ isError: false, data: { data: { status: 'ok' } } }),
  }
})

vi.mock('../../shared/ui/LanguageSwitcher', () => ({
  LanguageSwitcher: () => null,
}))

const success: KioskState = {
  step: 'success',
  attemptId: 'attempt-1',
  employee: { displayName: 'Nora Kiosk', employeeNumber: 'E-10' },
  terminalTimezone: 'Europe/Stockholm',
  attendanceState: 'working',
  allowedActions: [{ action: 'clock_in', photoRequired: true }],
  selectedAction: 'clock_in',
  occurredAt: '2026-10-08T07:00:00Z',
  messageKey: null,
  retry: null,
}

describe('kiosk result', () => {
  it('shows the employee, the server action, and the local time', async () => {
    await i18n.changeLanguage('en')
    const store = configureStore({
      reducer: {
        session: sessionReducer,
        branding: brandingReducer,
        kiosk: kioskReducer,
        [baseApi.reducerPath]: baseApi.reducer,
      },
      middleware: (getDefaultMiddleware) => getDefaultMiddleware().concat(baseApi.middleware),
      preloadedState: { kiosk: success },
    })

    render(<Provider store={store}><KioskPage /></Provider>)

    expect(screen.getByRole('heading', { name: 'Attendance recorded' })).toBeTruthy()
    expect(screen.getByText('Nora Kiosk')).toBeTruthy()
    expect(screen.getByText('Clock in')).toBeTruthy()
    expect(screen.getByText('Local time').nextElementSibling?.textContent).toContain('09:00:00')
    expect(screen.queryByRole('button', { name: 'Clock out' })).toBeNull()
    expect(store.getState().kiosk).not.toHaveProperty('code')
  })
})

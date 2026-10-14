import { formatTenantDateTime, formatTenantNumber, weekdays } from './format'

const instant = '2026-01-15T23:30:00Z'

describe('tenant time presentation', () => {
  it('formats the same UTC instant in each tenant timezone', () => {
    expect(formatTenantDateTime(instant, {
      timezone: 'Europe/Stockholm',
      dateFormat: 'iso',
      timeFormat: '24h',
    })).toBe('2026-01-16 00:30:00')

    expect(formatTenantDateTime(instant, {
      timezone: 'America/New_York',
      dateFormat: 'iso',
      timeFormat: '24h',
    })).toBe('2026-01-15 18:30:00')

    expect(formatTenantDateTime(instant, {
      timezone: 'Europe/Stockholm',
      dateFormat: 'dmy',
      timeFormat: '24h',
    })).toBe('16/01/2026 00:30:00')
  })

  it('formats numbers with the active language and falls back for an unknown language', () => {
    expect(formatTenantNumber(1234.5, 'en')).toBe('1,234.5')
    expect(formatTenantNumber(1234.5, 'sv').replace(/\s/g, ' ')).toBe('1 234,5')
    expect(formatTenantNumber(1234.5, 'de')).toBe('1,234.5')
  })

  it('orders the week from the tenant setting', () => {
    expect(weekdays(1)).toEqual([1, 2, 3, 4, 5, 6, 0])
    expect(weekdays(0)).toEqual([0, 1, 2, 3, 4, 5, 6])
  })
})

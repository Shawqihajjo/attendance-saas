import {
  browserTimeZone,
  durationSeconds,
  formatUtcDateTime,
  isIanaTimeZone,
  parseUtcInstant,
  resolveDisplayTimeZone,
} from './time'

const stored = '2026-10-07T06:42:15Z'

describe('UTC display helpers', () => {
  it('converts a UTC instant and rejects a display string as a duration', () => {
    expect(parseUtcInstant(stored)?.toISOString()).toBe('2026-10-07T06:42:15.000Z')
    expect(parseUtcInstant('2026-10-07 08:42:15')).toBeNull()
    expect(formatUtcDateTime(stored, 'UTC')).toBe('2026-10-07 06:42:15')
    expect(formatUtcDateTime(stored, 'Europe/Stockholm')).toBe('2026-10-07 08:42:15')
    expect(durationSeconds('2026-10-07 08:42:15', '2026-10-07 09:42:15')).toBeNull()
  })

  it('keeps Europe/Stockholm daylight-saving and midnight crossings on the UTC instant', () => {
    expect(formatUtcDateTime('2026-03-29T00:30:00Z', 'Europe/Stockholm')).toBe('2026-03-29 01:30:00')
    expect(formatUtcDateTime('2026-03-29T01:30:00Z', 'Europe/Stockholm')).toBe('2026-03-29 03:30:00')
    expect(durationSeconds('2026-03-29T00:30:00Z', '2026-03-29T01:30:00Z')).toBe(3600)

    expect(formatUtcDateTime('2026-10-25T00:30:00Z', 'Europe/Stockholm')).toBe('2026-10-25 02:30:00')
    expect(formatUtcDateTime('2026-10-25T01:30:00Z', 'Europe/Stockholm')).toBe('2026-10-25 02:30:00')
    expect(durationSeconds('2026-10-25T00:30:00Z', '2026-10-25T01:30:00Z')).toBe(3600)

    expect(formatUtcDateTime('2026-01-15T23:30:00Z', 'Europe/Stockholm')).toBe('2026-01-16 00:30:00')
    expect(formatUtcDateTime('2026-01-15T23:30:00Z', 'America/New_York')).toBe('2026-01-15 18:30:00')
  })

  it('selects tenant, terminal, report, and browser timezones independently', () => {
    expect(resolveDisplayTimeZone({ display: 'tenant', tenantTimeZone: 'Europe/Stockholm' })).toBe('Europe/Stockholm')
    expect(formatUtcDateTime(stored, resolveDisplayTimeZone({
      display: 'tenant',
      tenantTimeZone: 'Asia/Tokyo',
    }))).toBe('2026-10-07 15:42:15')

    expect(formatUtcDateTime(stored, resolveDisplayTimeZone({
      display: 'terminal',
      tenantTimeZone: 'Europe/Stockholm',
      terminalTimeZone: 'America/Chicago',
    }))).toBe('2026-10-07 01:42:15')
    expect(formatUtcDateTime(stored, resolveDisplayTimeZone({
      display: 'terminal',
      tenantTimeZone: 'Europe/Stockholm',
      terminalTimeZone: 'Pacific/Auckland',
    }))).toBe('2026-10-07 19:42:15')

    expect(formatUtcDateTime(stored, resolveDisplayTimeZone({
      display: 'report',
      tenantTimeZone: 'Europe/Stockholm',
      reportTimeZone: 'America/New_York',
    }))).toBe('2026-10-07 02:42:15')
    expect(resolveDisplayTimeZone({ display: 'report', tenantTimeZone: 'Europe/Stockholm' })).toBe('Europe/Stockholm')

    expect(formatUtcDateTime(stored, resolveDisplayTimeZone({
      display: 'browser',
      tenantTimeZone: 'Europe/Stockholm',
      browserTimeZone: 'Asia/Tokyo',
    }))).toBe('2026-10-07 15:42:15')
    expect(isIanaTimeZone(browserTimeZone())).toBe(true)
    for (const rejected of ['UTC+1', '+01:00', 'GMT', 'Etc/GMT-1']) {
      expect(isIanaTimeZone(rejected)).toBe(false)
      expect(() => resolveDisplayTimeZone({ display: 'report', tenantTimeZone: 'Europe/Stockholm', reportTimeZone: rejected })).toThrow(/IANA/)
    }
  })
})

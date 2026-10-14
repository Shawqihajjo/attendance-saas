import { describe, expect, it } from 'vitest'
import { durationParts, localDateInZone, presetRange } from './reportTime'

describe('report time', () => {
  it('chooses the calendar date in the report timezone', () => {
    const instant = new Date('2026-10-07T03:30:00Z')
    expect(localDateInZone('America/New_York', instant)).toBe('2026-10-06')
    expect(localDateInZone('Europe/Stockholm', instant)).toBe('2026-10-07')
  })

  it('builds daily, weekly, and monthly ranges from the calendar date', () => {
    expect(presetRange('day', '2026-10-08', 1)).toEqual({ from: '2026-10-08', to: '2026-10-08' })
    expect(presetRange('week', '2026-10-08', 1)).toEqual({ from: '2026-10-05', to: '2026-10-08' })
    expect(presetRange('week', '2026-10-08', 0)).toEqual({ from: '2026-10-04', to: '2026-10-08' })
    expect(presetRange('month', '2026-10-08', 1)).toEqual({ from: '2026-10-01', to: '2026-10-08' })
  })

  it('turns worked seconds into hours and minutes', () => {
    expect(durationParts(3600)).toEqual({ hours: 1, minutes: 0 })
    expect(durationParts(2700)).toEqual({ hours: 0, minutes: 45 })
    expect(durationParts(0)).toEqual({ hours: 0, minutes: 0 })
  })
})

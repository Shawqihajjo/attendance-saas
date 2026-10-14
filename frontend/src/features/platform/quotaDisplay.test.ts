import { limitIsBelowUsage, overallQuotaLevel, quotaNeedsIncrease, QUOTA_WARNING_RATIO, usagePercent, usagePercentState } from './quotaDisplay'
import type { QuotaMetric } from './platformApi'

function metric(used: number | null, limit: number | null, level: string): QuotaMetric {
  return {
    used,
    limit,
    remaining: limit === null || used === null ? null : Math.max(0, limit - used),
    level,
    overLimit: level === 'over',
  }
}

describe('quota display', () => {
  it('keeps the warning threshold aligned with four fifths of a limit', () => {
    expect(QUOTA_WARNING_RATIO).toBe(0.8)
  })

  it('reports the share of a finite limit and leaves unlimited usage without a percentage', () => {
    expect(usagePercent(metric(72, 100, 'ok'))).toBe(72)
    expect(usagePercent(metric(250, 250, 'reached'))).toBe(100)
    expect(usagePercent(metric(12, 10, 'over'))).toBe(120)
    expect(usagePercent(metric(4, null, 'ok'))).toBeNull()
    expect(usagePercent(metric(0, 0, 'reached'))).toBe(100)
    expect(usagePercent(metric(null, 10, 'unavailable'))).toBeNull()
    expect(usagePercent(metric(4, 0, 'over'))).toBeNull()
    expect(usagePercentState(metric(4, 0, 'over'))).toBe('over')
    expect(usagePercentState(metric(4, null, 'ok'))).toBe('unlimited')
    expect(usagePercentState(metric(null, 10, 'unavailable'))).toBe('unavailable')
    expect(usagePercentState(metric(72, 100, 'ok'))).toBe(72)
  })

  it('uses the strongest measured server level as the overall status', () => {
    expect(overallQuotaLevel({
      users: metric(72, 100, 'ok'),
      employees: metric(80, 100, 'warning'),
      devices: metric(1, null, 'ok'),
    })).toBe('warning')
    expect(overallQuotaLevel({
      users: metric(1, null, 'ok'),
      employees: metric(250, 250, 'reached'),
      devices: metric(11, 10, 'over'),
    })).toBe('over')
    expect(overallQuotaLevel({
      users: metric(null, 10, 'unavailable'),
      employees: metric(null, 10, 'unavailable'),
      devices: metric(0, null, 'ok'),
    })).toBe('ok')
  })

  it('offers an increase only when the server level is warning, reached, or over', () => {
    expect(quotaNeedsIncrease('warning')).toBe(true)
    expect(quotaNeedsIncrease('reached')).toBe(true)
    expect(quotaNeedsIncrease('over')).toBe(true)
    expect(quotaNeedsIncrease('ok')).toBe(false)
    expect(quotaNeedsIncrease('unavailable')).toBe(false)
  })

  it('treats a finite limit under current usage as a reduction', () => {
    expect(limitIsBelowUsage(9, metric(10, 10, 'reached'))).toBe(true)
    expect(limitIsBelowUsage(10, metric(10, 10, 'reached'))).toBe(false)
    expect(limitIsBelowUsage(null, metric(10, 10, 'reached'))).toBe(false)
    expect(limitIsBelowUsage(0, metric(null, 10, 'unavailable'))).toBe(false)
  })
})

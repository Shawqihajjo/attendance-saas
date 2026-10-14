import type { QuotaMetric } from './platformApi'

/** Matches the server warning threshold in QuotaUsage: four fifths of a finite limit. */
export const QUOTA_WARNING_RATIO = 0.8

const levelRank: Record<string, number> = {
  unavailable: 0,
  ok: 1,
  warning: 2,
  reached: 3,
  over: 4,
}

export function usagePercent(metric: QuotaMetric): number | null {
  if (metric.used === null || metric.limit === null) {
    return null
  }
  if (metric.limit === 0) {
    return metric.used === 0 ? 100 : null
  }

  return Math.round((metric.used / metric.limit) * 100)
}

/** A finite limit of zero with existing usage has no percentage. That state is over the limit. */
export function usagePercentState(metric: QuotaMetric): 'unlimited' | 'unavailable' | 'over' | number {
  if (metric.used === null) {
    return 'unavailable'
  }
  if (metric.limit === null) {
    return 'unlimited'
  }
  const percent = usagePercent(metric)

  return percent === null ? 'over' : percent
}

export function overallQuotaLevel(usage: { users: QuotaMetric; employees: QuotaMetric; devices: QuotaMetric }): string {
  const levels = [usage.users.level, usage.employees.level, usage.devices.level]
  const measured = levels.filter((level) => level !== 'unavailable')
  const source = measured.length > 0 ? measured : levels

  return source.reduce((worst, level) => (levelRank[level] ?? 0) > (levelRank[worst] ?? 0) ? level : worst)
}

export function limitIsBelowUsage(next: number | null, metric: QuotaMetric): boolean {
  return next !== null && metric.used !== null && next < metric.used
}

export function quotaNeedsIncrease(level: string): boolean {
  return level === 'warning' || level === 'reached' || level === 'over'
}

export function quotaStatusClass(level: string): string {
  if (level === 'ok') {
    return 'status status-ok'
  }
  if (level === 'warning') {
    return 'status'
  }
  if (level === 'reached' || level === 'over') {
    return 'status status-failed'
  }

  return 'status'
}

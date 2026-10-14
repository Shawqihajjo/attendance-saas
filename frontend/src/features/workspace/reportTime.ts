import { browserTimeZone, isIanaTimeZone, type TimeDisplaySource } from '../../shared/time/time'

export function localDateInZone(timeZone: string, date = new Date()): string {
  try {
    return new Intl.DateTimeFormat('en-CA', {
      timeZone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).format(date)
  } catch {
    return ''
  }
}

export function addCalendarDays(isoDate: string, days: number): string {
  const [year, month, day] = isoDate.split('-').map(Number)
  if (!year || !month || !day) {
    return isoDate
  }
  const utc = new Date(Date.UTC(year, month - 1, day + days))

  return utc.toISOString().slice(0, 10)
}

export function presetRange(preset: 'day' | 'week' | 'month', today: string, weekStartsOn: number): { from: string, to: string } {
  if (preset === 'day' || !/^\d{4}-\d{2}-\d{2}$/.test(today)) {
    return { from: today, to: today }
  }
  if (preset === 'month') {
    return { from: `${today.slice(0, 8)}01`, to: today }
  }
  const [year, month, day] = today.split('-').map(Number)
  const weekday = new Date(Date.UTC(year, month - 1, day)).getUTCDay()
  const start = weekStartsOn === 0 ? 0 : 1
  const delta = (weekday - start + 7) % 7

  return { from: addCalendarDays(today, -delta), to: today }
}

export function durationParts(seconds: number): { hours: number, minutes: number } {
  const safe = Number.isFinite(seconds) && seconds > 0 ? Math.floor(seconds) : 0

  return {
    hours: Math.floor(safe / 3600),
    minutes: Math.floor((safe % 3600) / 60),
  }
}

export function reportZone(display: TimeDisplaySource, tenantTimeZone: string, explicit: string): string {
  if (display === 'browser') {
    return browserTimeZone()
  }
  if (display === 'report' && isIanaTimeZone(explicit)) {
    return explicit.trim()
  }

  return tenantTimeZone
}

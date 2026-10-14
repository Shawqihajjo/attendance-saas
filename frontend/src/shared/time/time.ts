export type TimeDisplaySource = 'tenant' | 'terminal' | 'browser' | 'report'

const displayKey = 'timeDisplay'
const reportKey = 'reportTimeZone'
const offsetZone = /^(?:[+-]\d|UTC[+-]|GMT|Etc\/GMT)/i
const utcInstantPattern = /^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})(?:\.\d+)?(?:Z|\+00:00)$/

export function isIanaTimeZone(value: string): boolean {
  const zone = value.trim()
  if (zone === '' || offsetZone.test(zone)) {
    return false
  }
  try {
    Intl.DateTimeFormat('en-GB', { timeZone: zone }).format(0)
  } catch {
    return false
  }

  return zone.toUpperCase() === 'UTC' || zone.includes('/')
}

export function parseUtcInstant(value: string): Date | null {
  if (!utcInstantPattern.test(value)) {
    return null
  }
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) {
    return null
  }

  return date
}

export function browserTimeZone(): string {
  const zone = Intl.DateTimeFormat().resolvedOptions().timeZone
  if (!isIanaTimeZone(zone)) {
    return 'UTC'
  }

  return zone.toUpperCase() === 'UTC' ? 'UTC' : zone
}

export function resolveDisplayTimeZone(choice: {
  display: TimeDisplaySource
  tenantTimeZone: string
  terminalTimeZone?: string | null
  browserTimeZone?: string | null
  reportTimeZone?: string | null
}): string {
  const selected = choice.display === 'tenant'
    ? choice.tenantTimeZone
    : choice.display === 'terminal'
      ? choice.terminalTimeZone
      : choice.display === 'browser'
        ? choice.browserTimeZone
        : choice.reportTimeZone || choice.tenantTimeZone
  if (!selected || !isIanaTimeZone(selected)) {
    throw new Error('Timezone must be an IANA name.')
  }

  return selected.toUpperCase() === 'UTC' ? 'UTC' : selected
}

export function formatUtcDateTime(
  instant: string,
  timeZone: string,
  presentation: { dateFormat?: string, timeFormat?: string } = {},
): string {
  const date = parseUtcInstant(instant)
  if (date === null) {
    return ''
  }
  let parts: Intl.DateTimeFormatPart[]
  try {
    parts = new Intl.DateTimeFormat('en-GB', {
      timeZone,
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      hourCycle: presentation.timeFormat === '12h' ? 'h12' : 'h23',
    }).formatToParts(date)
  } catch {
    return instant
  }
  const value = Object.fromEntries(parts.map((part) => [part.type, part.value]))
  const year = value.year ?? ''
  const month = value.month ?? ''
  const day = value.day ?? ''
  const dateText = presentation.dateFormat === 'dmy'
    ? `${day}/${month}/${year}`
    : presentation.dateFormat === 'mdy'
      ? `${month}/${day}/${year}`
      : `${year}-${month}-${day}`
  const clock = `${value.hour ?? ''}:${value.minute ?? ''}:${value.second ?? ''}`
  const timeText = presentation.timeFormat === '12h' ? `${clock} ${value.dayPeriod ?? ''}`.trim() : clock

  return `${dateText} ${timeText}`
}

export function durationSeconds(start: string, end: string): number | null {
  const opened = parseUtcInstant(start)
  const closed = parseUtcInstant(end)
  if (opened === null || closed === null) {
    return null
  }

  return Math.trunc(closed.getTime() / 1000) - Math.trunc(opened.getTime() / 1000)
}

export function readTimeDisplay(): TimeDisplaySource {
  const stored = window.localStorage.getItem(displayKey)
  if (stored === 'tenant' || stored === 'terminal' || stored === 'browser' || stored === 'report') {
    return stored
  }

  return 'tenant'
}

export function persistTimeDisplay(value: TimeDisplaySource): void {
  window.localStorage.setItem(displayKey, value)
}

export function readReportTimeZone(): string {
  return window.localStorage.getItem(reportKey) ?? ''
}

export function persistReportTimeZone(value: string): void {
  window.localStorage.setItem(reportKey, value)
}

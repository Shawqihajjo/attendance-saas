import { catalogs, fallbackLanguage } from '../i18n/catalog'
import { formatUtcDateTime } from '../time/time'

export type TimePresentation = {
  timezone: string
  dateFormat: string
  timeFormat: string
}

export function formatTenantDateTime(instant: string, presentation: TimePresentation): string {
  return formatUtcDateTime(instant, presentation.timezone, presentation)
}

export function formatTenantNumber(value: number, locale: string): string {
  const language = locale in catalogs ? locale : fallbackLanguage

  return new Intl.NumberFormat(language).format(value)
}

export function weekdays(weekStartsOn: number): number[] {
  const start = weekStartsOn === 0 ? 0 : 1

  return Array.from({ length: 7 }, (_, index) => (start + index) % 7)
}

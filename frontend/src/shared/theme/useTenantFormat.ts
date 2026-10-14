import { useTranslation } from 'react-i18next'
import { useAppSelector } from '../../app/hooks'
import { formatTenantDateTime, formatTenantNumber } from './format'

export function useTenantFormat() {
  const branding = useAppSelector((state) => state.branding.branding)
  const { i18n } = useTranslation()
  const presentation = {
    timezone: branding?.timezone ?? 'UTC',
    dateFormat: branding?.dateFormat ?? 'iso',
    timeFormat: branding?.timeFormat ?? '24h',
  }

  return {
    dateTime(instant: string, timeZone?: string | null) {
      const zone = timeZone && timeZone !== '' ? timeZone : presentation.timezone
      return formatTenantDateTime(instant, { ...presentation, timezone: zone })
    },
    number(value: number) {
      return formatTenantNumber(value, i18n.resolvedLanguage ?? i18n.language)
    },
  }
}

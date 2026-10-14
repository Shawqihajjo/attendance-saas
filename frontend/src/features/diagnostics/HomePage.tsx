import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useGetBrandingQuery } from '../branding/brandingApi'
import { errorMessageKey } from '../../shared/api/problems'
import { formatTenantDateTime } from '../../shared/theme/format'
import {
  browserTimeZone,
  persistReportTimeZone,
  persistTimeDisplay,
  readReportTimeZone,
  readTimeDisplay,
  resolveDisplayTimeZone,
  type TimeDisplaySource,
} from '../../shared/time/time'
import { Alert } from '../../shared/ui/Alert'
import { Page } from '../../shared/ui/Page'
import { Spinner } from '../../shared/ui/Spinner'
import { useGetHealthQuery, useGetReadinessQuery } from './diagnosticsApi'

export function HomePage() {
  const { t } = useTranslation()
  const branding = useGetBrandingQuery()
  const health = useGetHealthQuery()
  const readiness = useGetReadinessQuery()
  const [display, setDisplay] = useState<TimeDisplaySource>(() => {
    const stored = readTimeDisplay()
    return stored === 'terminal' ? 'tenant' : stored
  })
  const [reportZone, setReportZone] = useState(readReportTimeZone)
  const loading = health.isLoading || readiness.isLoading
  const error = health.error ?? readiness.error
  const presentation = branding.data && health.data ? displayedTime(
    health.data.data.time,
    branding.data.data,
    display,
    reportZone,
  ) : null

  return (
    <Page title={t('home.title')} intro={t('home.intro')}>
      {loading ? (
        <p className="toolbar">
          <Spinner /> {t('home.loading')}
        </p>
      ) : null}
      {error ? <Alert>{t(errorMessageKey(error))}</Alert> : null}
      <div className="card-grid">
        <article className="card">
          <h2>{t('home.liveness')}</h2>
          <StatusPill status={health.data?.data.status} />
          {health.data ? <p className="muted">{t('home.officialTime', { value: health.data.data.time })}</p> : null}
          {branding.data ? (
            <div className="toolbar">
              <label>
                {t('home.timeDisplay')}
                <select
                  value={display}
                  onChange={(event) => {
                    const next = event.target.value as TimeDisplaySource
                    setDisplay(next)
                    persistTimeDisplay(next)
                  }}
                >
                  <option value="tenant">{t('home.timeTenant')}</option>
                  <option value="browser">{t('home.timeBrowser')}</option>
                  <option value="report">{t('home.timeReport')}</option>
                </select>
              </label>
              {display === 'report' ? (
                <label>
                  {t('home.timeZone')}
                  <input
                    value={reportZone}
                    onChange={(event) => {
                      setReportZone(event.target.value)
                      persistReportTimeZone(event.target.value)
                    }}
                    placeholder={branding.data.data.timezone}
                  />
                </label>
              ) : null}
            </div>
          ) : null}
          {presentation?.error ? <Alert>{t('home.timeInvalid')}</Alert> : null}
          {presentation && !presentation.error ? (
            <p className="muted">{t('home.localTime', { value: presentation.value })}</p>
          ) : null}
          {presentation && !presentation.error ? (
            <p className="muted">{t('home.shownIn', { timezone: presentation.timezone })}</p>
          ) : null}
        </article>
        {Object.entries(readiness.data?.data.checks ?? {}).map(([name, check]) => (
          <article className="card" key={name}>
            <h2>{t(`home.check.${name}`)}</h2>
            <StatusPill status={check.status} />
            {check.detail ? <p className="muted">{check.detail}</p> : null}
          </article>
        ))}
      </div>
    </Page>
  )
}

function displayedTime(
  instant: string,
  branding: { timezone: string, dateFormat: string, timeFormat: string },
  display: TimeDisplaySource,
  reportZone: string,
): { value: string, timezone: string, error: boolean } {
  try {
    const timezone = resolveDisplayTimeZone({
      display,
      tenantTimeZone: branding.timezone,
      browserTimeZone: browserTimeZone(),
      reportTimeZone: reportZone || branding.timezone,
    })

    return {
      timezone,
      error: false,
      value: formatTenantDateTime(instant, {
        timezone,
        dateFormat: branding.dateFormat,
        timeFormat: branding.timeFormat,
      }),
    }
  } catch {
    return { value: '', timezone: '', error: true }
  }
}

function StatusPill({ status }: { status?: string }) {
  const { t } = useTranslation()
  const tone = status === 'ok' || status === 'unselected' || status === 'disabled' ? 'status-ok' : status === 'failed' ? 'status-failed' : ''
  const known = status === 'ok' || status === 'failed' || status === 'unselected' || status === 'disabled'
  const label = known ? t(`status.${status}`) : (status ?? t('status.unknown'))
  return <span className={`status ${tone}`.trim()}>{label}</span>
}

import { useTranslation } from 'react-i18next'
import { browserTimeZone } from '../../shared/time/time'
import { useTenantFormat } from '../../shared/theme/useTenantFormat'
import { Button } from '../../shared/ui/Button'
import type { PageInfo } from './workspaceApi'

export function PresentationFields({
  display,
  timezone,
  tenantTimeZone,
  includeTerminal,
  onDisplay,
  onTimezone,
}: {
  display: string
  timezone: string
  tenantTimeZone: string
  includeTerminal: boolean
  onDisplay: (value: string) => void
  onTimezone: (value: string) => void
}) {
  const { t } = useTranslation()

  return (
    <>
      <label className="field">
        {t('home.timeDisplay')}
        <select value={display} onChange={(event) => onDisplay(event.target.value)}>
          <option value="tenant">{t('home.timeTenant')}</option>
          {includeTerminal ? <option value="terminal">{t('workspace.dashboard.timeTerminal')}</option> : null}
          <option value="browser">{t('home.timeBrowser')}</option>
          <option value="report">{t('home.timeReport')}</option>
        </select>
      </label>
      {display === 'report' ? (
        <label className="field">
          {t('home.timeZone')}
          <input value={timezone} placeholder={tenantTimeZone} onChange={(event) => onTimezone(event.target.value)} />
        </label>
      ) : null}
      {display === 'browser' ? <p className="muted">{t('home.shownIn', { timezone: browserTimeZone() })}</p> : null}
    </>
  )
}

export function Pager({ page, onOffset }: { page: PageInfo, onOffset: (offset: number) => void }) {
  const { t } = useTranslation()
  const format = useTenantFormat()
  const from = page.total === 0 ? 0 : page.offset + 1
  const to = Math.min(page.offset + page.limit, page.total)

  return (
    <div className="toolbar">
      <Button type="button" variant="secondary" disabled={page.offset <= 0} onClick={() => onOffset(Math.max(0, page.offset - page.limit))}>
        {t('workspace.dashboard.previous')}
      </Button>
      <span className="muted">
        {t('workspace.dashboard.page', { from: format.number(from), to: format.number(to), total: format.number(page.total) })}
      </span>
      <Button type="button" variant="secondary" disabled={page.offset + page.limit >= page.total} onClick={() => onOffset(page.offset + page.limit)}>
        {t('workspace.dashboard.next')}
      </Button>
    </div>
  )
}

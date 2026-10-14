import { useState } from 'react'
import { NavLink } from 'react-router'
import { useTranslation } from 'react-i18next'
import { useAppSelector } from '../../app/hooks'
import type { WorkspaceArea } from '../../app/access'
import { browserTimeZone } from '../../shared/time/time'
import { useTenantFormat } from '../../shared/theme/useTenantFormat'
import { Page } from '../../shared/ui/Page'
import { QueryState } from '../../shared/ui/QueryState'
import { Alert } from '../../shared/ui/Alert'
import { AreaLinks } from './AreaDashboard'
import { AttendanceTable } from './AttendancePages'
import { Pager, PresentationFields } from './ReportControls'
import { durationParts } from './reportTime'
import { useGetHrDashboardQuery, useGetMyDashboardQuery, type DashboardFilters, type HrDashboard, type InstantStamp, type MyDashboard } from './workspaceApi'

function useDurationText(): (seconds: number) => string {
  const { t } = useTranslation()
  const format = useTenantFormat()

  return (seconds: number) => {
    const parts = durationParts(seconds)
    return t('workspace.dashboard.duration', {
      hours: format.number(parts.hours),
      minutes: format.number(parts.minutes),
    })
  }
}

function dashboardParams(display: string, timezone: string, date: string, offset: number): DashboardFilters {
  return {
    display,
    limit: 25,
    offset,
    ...(date !== '' ? { date } : {}),
    ...(display === 'browser' ? { browserTimezone: browserTimeZone() } : {}),
    ...(display === 'report' && timezone.trim() !== '' ? { timezone: timezone.trim() } : {}),
  }
}

export function EmployeeDashboard() {
  const { t } = useTranslation()
  const format = useTenantFormat()
  const duration = useDurationText()
  const tenantTimeZone = useAppSelector((state) => state.branding.branding?.timezone ?? 'UTC')
  const [display, setDisplay] = useState('tenant')
  const [timezone, setTimezone] = useState('')
  const [date, setDate] = useState('')
  const [offset, setOffset] = useState(0)
  const dashboard = useGetMyDashboardQuery(dashboardParams(display, timezone, date, offset))
  const data = dashboard.data?.data

  return (
    <Page title={t('workspace.myDashboard')} intro={t('workspace.myDashboardIntro')}>
      <div className="card-grid">
        <PresentationFields
          display={display}
          timezone={timezone}
          tenantTimeZone={tenantTimeZone}
          includeTerminal={false}
          onDisplay={(value) => { setDisplay(value); setOffset(0) }}
          onTimezone={(value) => { setTimezone(value); setOffset(0) }}
        />
        <label className="field">
          {t('workspace.dashboard.date')}
          <input type="date" value={date} onChange={(event) => { setDate(event.target.value); setOffset(0) }} />
        </label>
      </div>
      <QueryState isLoading={dashboard.isLoading} error={dashboard.error} empty={false} emptyLabel={t('workspace.noAttendance')}>
        {data ? <EmployeeSummary data={data} duration={duration} formatTime={(instant) => format.dateTime(instant, data.timezone)} /> : null}
      </QueryState>
      {data ? <Pager page={data.history.page} onOffset={setOffset} /> : null}
      <AreaLinks area="employee" home="/me" />
    </Page>
  )
}

function EmployeeSummary({
  data,
  duration,
  formatTime,
}: {
  data: MyDashboard
  duration: (seconds: number) => string
  formatTime: (instant: string) => string
}) {
  const { t } = useTranslation()

  return (
    <>
      {data.missingClockOut ? <Alert>{t('workspace.dashboard.missing')}</Alert> : null}
      <p className="muted">{t('home.shownIn', { timezone: data.timezone ?? t('workspace.dashboard.none') })}</p>
      <div className="card-grid">
        <article className="card">
          <h2>{t('workspace.dashboard.state')}</h2>
          <p>{t(`workspace.dashboard.states.${data.state}`, { defaultValue: data.state })}</p>
        </article>
        <article className="card">
          <h2>{t('workspace.dashboard.clockIn')}</h2>
          <p>{stampText(data.clockIn, formatTime, t('workspace.dashboard.none'))}</p>
        </article>
        <article className="card">
          <h2>{t('workspace.dashboard.clockOut')}</h2>
          <p>{stampText(data.clockOut, formatTime, t('workspace.dashboard.none'))}</p>
        </article>
        <article className="card">
          <h2>{t('workspace.dashboard.worked')}</h2>
          <p>{duration(data.workedSeconds)}</p>
        </article>
      </div>
      <section className="panel">
        <h2>{t('workspace.dashboard.lunches')}</h2>
        {data.lunches.length === 0 ? <p className="muted">{t('workspace.dashboard.noLunch')}</p> : (
          <ul>
            {data.lunches.map((lunch) => (
              <li key={lunch.start.occurredAt}>
                {formatTime(lunch.start.occurredAt)}
                {' – '}
                {lunch.end ? formatTime(lunch.end.occurredAt) : t('workspace.dashboard.lunchOpen')}
                {' · '}
                {duration(lunch.seconds)}
              </li>
            ))}
          </ul>
        )}
      </section>
      <h2>{t('workspace.history')}</h2>
      {data.history.records.length === 0 ? <p className="muted">{t('workspace.noAttendance')}</p> : <AttendanceTable records={data.history.records} />}
    </>
  )
}

export function ManagerDashboard() {
  return <OrganizationDashboard area="manager" home="/team" titleKey="workspace.teamDashboard" introKey="workspace.teamDashboardIntro" correctionsTo="/team/corrections" />
}

export function HrDashboard() {
  return <OrganizationDashboard area="hr" home="/hr" titleKey="workspace.hrDashboard" introKey="workspace.hrDashboardIntro" correctionsTo="/hr/corrections" />
}

function OrganizationDashboard({
  area,
  home,
  titleKey,
  introKey,
  correctionsTo,
}: {
  area: WorkspaceArea
  home: string
  titleKey: string
  introKey: string
  correctionsTo: string
}) {
  const { t } = useTranslation()
  const format = useTenantFormat()
  const duration = useDurationText()
  const tenantTimeZone = useAppSelector((state) => state.branding.branding?.timezone ?? 'UTC')
  const [display, setDisplay] = useState('tenant')
  const [timezone, setTimezone] = useState('')
  const [date, setDate] = useState('')
  const [offset, setOffset] = useState(0)
  const dashboard = useGetHrDashboardQuery(dashboardParams(display, timezone, date, offset))
  const data = dashboard.data?.data

  return (
    <Page title={t(titleKey)} intro={t(introKey)}>
      <div className="card-grid">
        <PresentationFields
          display={display}
          timezone={timezone}
          tenantTimeZone={tenantTimeZone}
          includeTerminal={false}
          onDisplay={(value) => { setDisplay(value); setOffset(0) }}
          onTimezone={(value) => { setTimezone(value); setOffset(0) }}
        />
        <label className="field">
          {t('workspace.dashboard.date')}
          <input type="date" value={date} onChange={(event) => { setDate(event.target.value); setOffset(0) }} />
        </label>
      </div>
      <QueryState isLoading={dashboard.isLoading} error={dashboard.error} empty={false} emptyLabel={t('workspace.noAttendance')}>
        {data ? (
          <OrganizationSummary
            data={data}
            correctionsTo={correctionsTo}
            duration={duration}
            formatTime={(instant) => format.dateTime(instant, data.timezone)}
          />
        ) : null}
      </QueryState>
      {data ? <Pager page={data.daily.page} onOffset={setOffset} /> : null}
      <AreaLinks area={area} home={home} />
    </Page>
  )
}

function OrganizationSummary({
  data,
  correctionsTo,
  duration,
  formatTime,
}: {
  data: HrDashboard
  correctionsTo: string
  duration: (seconds: number) => string
  formatTime: (instant: string) => string
}) {
  const { t } = useTranslation()
  const format = useTenantFormat()

  return (
    <>
      <p className="muted">{t('workspace.dashboard.forDate', { date: data.date })}</p>
      <p className="muted">{t('home.shownIn', { timezone: data.timezone })}</p>
      <div className="card-grid">
        <PeopleCard title={t('workspace.dashboard.workingNow')} count={data.counts.working} people={data.working} formatTime={formatTime} />
        <PeopleCard title={t('workspace.dashboard.onLunchNow')} count={data.counts.onLunch} people={data.onLunch} formatTime={formatTime} />
        <PeopleCard title={t('workspace.dashboard.notIn')} count={data.counts.off} people={data.off} formatTime={formatTime} />
        <article className="card">
          <h2>{t('workspace.dashboard.exceptions')}</h2>
          <p>{format.number(data.exceptionCount)}</p>
          {data.exceptions.length === 0 ? <p className="muted">{t('workspace.empty')}</p> : data.exceptions.map((item) => (
            <p key={item.id}>{item.name} · {t('workspace.dashboard.missing')}</p>
          ))}
        </article>
        <NavLink className="card dashboard-link" to={correctionsTo}>
          <h2>{t('workspace.dashboard.pendingCorrections')}</h2>
          <p>{format.number(data.corrections.pending)}</p>
        </NavLink>
      </div>
      <h2>{t('workspace.dashboard.daily')}</h2>
      {data.daily.records.length === 0 ? <p className="muted">{t('workspace.noAttendance')}</p> : (
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('table.employee')}</th>
                <th>{t('table.state')}</th>
                <th>{t('workspace.dashboard.clockIn')}</th>
                <th>{t('workspace.dashboard.clockOut')}</th>
                <th>{t('table.worked')}</th>
                <th>{t('table.lunch')}</th>
              </tr>
            </thead>
            <tbody>
              {data.daily.records.map((row) => (
                <tr key={row.employeeId}>
                  <td>{row.name}</td>
                  <td>
                    {t(`workspace.dashboard.states.${row.state}`, { defaultValue: row.state })}
                    {row.missingClockOut ? ` (${t('workspace.dashboard.missing')})` : ''}
                  </td>
                  <td>{stampText(row.clockIn, formatTime, t('workspace.dashboard.none'))}</td>
                  <td>{stampText(row.clockOut, formatTime, t('workspace.dashboard.none'))}</td>
                  <td>{duration(row.workedSeconds)}</td>
                  <td>{duration(row.lunchSeconds)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  )
}

function PeopleCard({
  title,
  count,
  people,
  formatTime,
}: {
  title: string
  count: number
  people: HrDashboard['working']
  formatTime: (instant: string) => string
}) {
  const { t } = useTranslation()
  const format = useTenantFormat()

  return (
    <article className="card">
      <h2>{title}</h2>
      <p>{format.number(count)}</p>
      {people.length === 0 ? <p className="muted">{t('workspace.empty')}</p> : (
        <ul>
          {people.map((person) => (
            <li key={person.id}>
              {person.name}
              {person.since ? ` · ${formatTime(person.since.occurredAt)}` : ''}
            </li>
          ))}
        </ul>
      )}
    </article>
  )
}

function stampText(stamp: InstantStamp | null, formatTime: (instant: string) => string, empty: string): string {
  if (!stamp) {
    return empty
  }

  return formatTime(stamp.occurredAt)
}

import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useAppSelector } from '../../app/hooks'
import { browserTimeZone, type TimeDisplaySource } from '../../shared/time/time'
import { useTenantFormat } from '../../shared/theme/useTenantFormat'
import { Button } from '../../shared/ui/Button'
import { Page } from '../../shared/ui/Page'
import { QueryState } from '../../shared/ui/QueryState'
import { useGetDepartmentsQuery, useGetEmployeesQuery, useGetTeamsQuery } from '../people/peopleApi'
import { Pager, PresentationFields } from './ReportControls'
import { localDateInZone, presetRange, reportZone } from './reportTime'
import { useGetMyAttendanceQuery, useGetReportsQuery, useGetReviewedAttendanceQuery, type AttendanceRecord, type ReportFilters } from './workspaceApi'

function localToday(timeZone: string | null): string {
  try {
    return new Intl.DateTimeFormat('en-CA', {
      timeZone: timeZone && timeZone.includes('/') ? timeZone : 'UTC',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).format(new Date())
  } catch {
    return ''
  }
}

export function AttendanceTable({ records, showEmployee = false }: { records: AttendanceRecord[], showEmployee?: boolean }) {
  const { t } = useTranslation()
  const format = useTenantFormat()

  return (
    <div className="table-wrap">
      <table className="data-table">
        <thead>
          <tr>
            {showEmployee ? <th>{t('table.employee')}</th> : null}
            <th>{t('table.action')}</th>
            <th>{t('table.time')}</th>
            <th>{t('table.timezone')}</th>
          </tr>
        </thead>
        <tbody>
          {records.map((record) => (
            <tr key={record.id}>
              {showEmployee ? <td>{record.employeeName ?? record.employeeId}</td> : null}
              <td>{t(`attendance.${record.eventType}`, { defaultValue: record.eventType })}</td>
              <td>{format.dateTime(record.occurredAt, record.timezone)}</td>
              <td>{record.timezone}</td>
            </tr>
          ))}
        </tbody>
      </table>
      <p className="muted">{t('table.count', { count: records.length, formatted: format.number(records.length) })}</p>
    </div>
  )
}

export function MyAttendancePage({ todayOnly }: { todayOnly: boolean }) {
  const { t } = useTranslation()
  const branding = useAppSelector((state) => state.branding.branding)
  const [offset, setOffset] = useState(0)
  const date = todayOnly ? localToday(branding?.timezone ?? null) : ''
  const attendance = useGetMyAttendanceQuery({ limit: 25, offset, ...(date !== '' ? { date } : {}) })
  const records = attendance.data?.data.records ?? []
  const page = attendance.data?.data.page

  return (
    <Page title={t(todayOnly ? 'workspace.myAttendance' : 'workspace.history')} intro={t(todayOnly ? 'workspace.myAttendanceIntro' : 'workspace.historyIntro')}>
      <QueryState isLoading={attendance.isLoading} error={attendance.error} empty={records.length === 0} emptyLabel={t('workspace.noAttendance')}>
        <AttendanceTable records={records} />
      </QueryState>
      {page ? <Pager page={page} onOffset={setOffset} /> : null}
    </Page>
  )
}

export function ReviewedAttendancePage({ titleKey }: { titleKey: string }) {
  const { t } = useTranslation()
  const [offset, setOffset] = useState(0)
  const attendance = useGetReviewedAttendanceQuery({ limit: 25, offset })
  const records = attendance.data?.data.records ?? []
  const page = attendance.data?.data.page

  return (
    <Page title={t(titleKey)} intro={t('workspace.reviewIntro')}>
      <QueryState isLoading={attendance.isLoading} error={attendance.error} empty={records.length === 0} emptyLabel={t('workspace.noAttendance')}>
        <AttendanceTable records={records} />
      </QueryState>
      {page ? <Pager page={page} onOffset={setOffset} /> : null}
    </Page>
  )
}

export function ReportsPage({ titleKey }: { titleKey: string }) {
  const { t } = useTranslation()
  const permissions = useAppSelector((state) => state.session.permissions)
  const branding = useAppSelector((state) => state.branding.branding)
  const canOrganize = permissions.includes('organization.manage')
  const employees = useGetEmployeesQuery()
  const departments = useGetDepartmentsQuery(undefined, { skip: !canOrganize })
  const teams = useGetTeamsQuery(undefined, { skip: !canOrganize })
  const tenantTimeZone = branding?.timezone ?? 'UTC'
  const [display, setDisplay] = useState<TimeDisplaySource>('tenant')
  const [timezone, setTimezone] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [employeeId, setEmployeeId] = useState('')
  const [teamId, setTeamId] = useState('')
  const [departmentId, setDepartmentId] = useState('')
  const [eventType, setEventType] = useState('')
  const [status, setStatus] = useState('')
  const [offset, setOffset] = useState(0)
  const filters: ReportFilters = {
    display,
    limit: 25,
    offset,
    ...(from !== '' && to !== '' ? { from, to } : {}),
    ...(employeeId !== '' ? { employeeId } : {}),
    ...(canOrganize && teamId !== '' ? { teamId } : {}),
    ...(canOrganize && departmentId !== '' ? { departmentId } : {}),
    ...(eventType !== '' ? { eventType } : {}),
    ...(status !== '' ? { status } : {}),
    ...(display === 'browser' ? { browserTimezone: browserTimeZone() } : {}),
    ...(display === 'report' && timezone.trim() !== '' ? { timezone: timezone.trim() } : {}),
  }
  const reports = useGetReportsQuery(filters)
  const records = reports.data?.data.records ?? []
  const zone = reportZone(display, tenantTimeZone, timezone)

  function applyPreset(preset: 'day' | 'week' | 'month') {
    const today = localDateInZone(zone)
    const range = presetRange(preset, today, branding?.weekStartsOn === 0 ? 0 : 1)
    setFrom(range.from)
    setTo(range.to)
    setOffset(0)
  }

  return (
    <Page title={t(titleKey)} intro={t('workspace.reportsIntro', { scope: t(`workspace.scope.${reports.data?.data.scope ?? 'tenant'}`) })}>
      <form className="panel wide" onSubmit={(event) => event.preventDefault()}>
        <div className="card-grid">
          <label className="field">
            {t('workspace.reports.employee')}
            <select value={employeeId} onChange={(event) => { setEmployeeId(event.target.value); setOffset(0) }}>
              <option value="">{t('workspace.reports.all')}</option>
              {(employees.data?.data ?? []).map((employee) => (
                <option key={employee.id} value={employee.id}>{employee.name}</option>
              ))}
            </select>
          </label>
          {canOrganize ? (
            <label className="field">
              {t('people.department')}
              <select value={departmentId} onChange={(event) => { setDepartmentId(event.target.value); setOffset(0) }}>
                <option value="">{t('workspace.reports.all')}</option>
                {(departments.data?.data ?? []).map((department) => (
                  <option key={department.id} value={department.id}>{department.name}</option>
                ))}
              </select>
            </label>
          ) : null}
          {canOrganize ? (
            <label className="field">
              {t('people.team')}
              <select value={teamId} onChange={(event) => { setTeamId(event.target.value); setOffset(0) }}>
                <option value="">{t('workspace.reports.all')}</option>
                {(teams.data?.data ?? []).map((team) => (
                  <option key={team.id} value={team.id}>{team.name}</option>
                ))}
              </select>
            </label>
          ) : null}
          <label className="field">
            {t('workspace.reports.from')}
            <input type="date" value={from} onChange={(event) => { setFrom(event.target.value); setOffset(0) }} />
          </label>
          <label className="field">
            {t('workspace.reports.to')}
            <input type="date" value={to} onChange={(event) => { setTo(event.target.value); setOffset(0) }} />
          </label>
          <label className="field">
            {t('workspace.reports.eventType')}
            <select value={eventType} onChange={(event) => { setEventType(event.target.value); setOffset(0) }}>
              <option value="">{t('workspace.reports.all')}</option>
              <option value="clock_in">{t('attendance.clock_in')}</option>
              <option value="lunch_start">{t('attendance.lunch_start')}</option>
              <option value="lunch_end">{t('attendance.lunch_end')}</option>
              <option value="clock_out">{t('attendance.clock_out')}</option>
            </select>
          </label>
          <label className="field">
            {t('workspace.reports.status')}
            <select value={status} onChange={(event) => { setStatus(event.target.value); setOffset(0) }}>
              <option value="">{t('workspace.reports.all')}</option>
              <option value="working">{t('workspace.dashboard.states.working')}</option>
              <option value="on_lunch">{t('workspace.dashboard.states.on_lunch')}</option>
              <option value="off">{t('workspace.dashboard.states.off')}</option>
            </select>
          </label>
          <PresentationFields
            display={display}
            timezone={timezone}
            tenantTimeZone={tenantTimeZone}
            includeTerminal
            onDisplay={(value) => { setDisplay(value as TimeDisplaySource); setOffset(0) }}
            onTimezone={(value) => { setTimezone(value); setOffset(0) }}
          />
        </div>
        <div className="toolbar">
          <Button type="button" variant="secondary" onClick={() => applyPreset('day')}>{t('workspace.reports.daily')}</Button>
          <Button type="button" variant="secondary" onClick={() => applyPreset('week')}>{t('workspace.reports.weekly')}</Button>
          <Button type="button" variant="secondary" onClick={() => applyPreset('month')}>{t('workspace.reports.monthly')}</Button>
        </div>
      </form>
      {reports.data?.data.timezone ? <p className="muted">{t('home.shownIn', { timezone: reports.data.data.timezone })}</p> : null}
      <QueryState isLoading={reports.isLoading} error={reports.error} empty={records.length === 0} emptyLabel={t('workspace.noAttendance')}>
        <AttendanceTable records={records} showEmployee />
      </QueryState>
      {reports.data ? <Pager page={reports.data.data.page} onOffset={setOffset} /> : null}
    </Page>
  )
}

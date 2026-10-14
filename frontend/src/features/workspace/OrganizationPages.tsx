import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { requiredText } from '../../shared/forms/validate'
import { errorMessageKey } from '../../shared/api/problems'
import { Alert } from '../../shared/ui/Alert'
import { Button } from '../../shared/ui/Button'
import { Page } from '../../shared/ui/Page'
import { QueryState } from '../../shared/ui/QueryState'
import { useCreateDepartmentMutation, useCreateTeamMutation, useGetDepartmentsQuery, useGetEmployeesQuery, useGetTeamsQuery } from '../people/peopleApi'
import { useCreateScheduleMutation, useGetSchedulesQuery } from './workspaceApi'

export function DepartmentsPage() {
  const { t } = useTranslation()
  const departments = useGetDepartmentsQuery()
  const [create, state] = useCreateDepartmentMutation()
  const [name, setName] = useState('')
  const [fieldError, setFieldError] = useState<string | null>(null)
  const records = departments.data?.data ?? []

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    const invalid = requiredText(name)
    setFieldError(invalid)
    if (invalid) {
      return
    }
    try {
      await create({ name: name.trim() }).unwrap()
      setName('')
    } catch {
      setFieldError(null)
    }
  }

  return (
    <Page title={t('workspace.departments')} intro={t('workspace.departmentsIntro')}>
      <div className="split form-first">
      <form className="panel" onSubmit={(event) => { void onSubmit(event) }}>
        <label className="field">{t('people.department')}<input value={name} onChange={(event) => setName(event.target.value)} /></label>
        {fieldError ? <Alert>{t(fieldError)}</Alert> : null}
        {state.error ? <Alert>{t(errorMessageKey(state.error))}</Alert> : null}
        <Button type="submit" disabled={state.isLoading}>{t('people.create')}</Button>
      </form>
      <QueryState isLoading={departments.isLoading} error={departments.error} empty={records.length === 0} emptyLabel={t('workspace.empty')}>
        <div className="card-grid">{records.map((department) => <article className="card" key={department.id}><h2>{department.name}</h2></article>)}</div>
      </QueryState>
      </div>
    </Page>
  )
}

export function TeamsPage() {
  const { t } = useTranslation()
  const teams = useGetTeamsQuery()
  const departments = useGetDepartmentsQuery()
  const [create, state] = useCreateTeamMutation()
  const [name, setName] = useState('')
  const [departmentId, setDepartmentId] = useState('')
  const [fieldError, setFieldError] = useState<string | null>(null)
  const records = teams.data?.data ?? []

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    const invalid = requiredText(name)
    setFieldError(invalid)
    if (invalid) {
      return
    }
    try {
      await create({ name: name.trim(), departmentId: departmentId === '' ? null : departmentId }).unwrap()
      setName('')
    } catch {
      setFieldError(null)
    }
  }

  return (
    <Page title={t('workspace.teams')} intro={t('workspace.teamsIntro')}>
      <div className="split form-first">
      <form className="panel" onSubmit={(event) => { void onSubmit(event) }}>
        <label className="field">{t('people.team')}<input value={name} onChange={(event) => setName(event.target.value)} /></label>
        <label className="field">
          {t('people.department')}
          <select value={departmentId} onChange={(event) => setDepartmentId(event.target.value)}>
            <option value="">{t('people.none')}</option>
            {(departments.data?.data ?? []).map((department) => <option key={department.id} value={department.id}>{department.name}</option>)}
          </select>
        </label>
        {fieldError ? <Alert>{t(fieldError)}</Alert> : null}
        {state.error ? <Alert>{t(errorMessageKey(state.error))}</Alert> : null}
        <Button type="submit" disabled={state.isLoading}>{t('people.create')}</Button>
      </form>
      <QueryState isLoading={teams.isLoading} error={teams.error} empty={records.length === 0} emptyLabel={t('workspace.empty')}>
        <div className="card-grid">{records.map((team) => <article className="card" key={team.id}><h2>{team.name}</h2></article>)}</div>
      </QueryState>
      </div>
    </Page>
  )
}

export function SchedulesPage() {
  const { t } = useTranslation()
  const schedules = useGetSchedulesQuery()
  const employees = useGetEmployeesQuery()
  const [create, state] = useCreateScheduleMutation()
  const [employeeId, setEmployeeId] = useState('')
  const [name, setName] = useState('')
  const [fieldError, setFieldError] = useState<string | null>(null)
  const records = schedules.data?.data ?? []

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    const invalid = requiredText(name)
    setFieldError(employeeId === '' ? 'workspace.invalidText' : invalid)
    if (employeeId === '' || invalid) {
      return
    }
    try {
      await create({ employeeId, name: name.trim() }).unwrap()
      setName('')
    } catch {
      setFieldError(null)
    }
  }

  return (
    <Page title={t('workspace.schedules')} intro={t('workspace.schedulesIntro')}>
      <div className="split form-first">
      <form className="panel" onSubmit={(event) => { void onSubmit(event) }}>
        <label className="field">
          {t('workspace.employee')}
          <select value={employeeId} onChange={(event) => setEmployeeId(event.target.value)}>
            <option value="">{t('people.none')}</option>
            {(employees.data?.data ?? []).map((employee) => <option key={employee.id} value={employee.id}>{employee.name}</option>)}
          </select>
        </label>
        <label className="field">{t('people.schedule')}<input value={name} onChange={(event) => setName(event.target.value)} /></label>
        {fieldError ? <Alert>{t(fieldError)}</Alert> : null}
        {state.error ? <Alert>{t(errorMessageKey(state.error))}</Alert> : null}
        <Button type="submit" disabled={state.isLoading}>{t('people.create')}</Button>
      </form>
      <QueryState isLoading={schedules.isLoading} error={schedules.error} empty={records.length === 0} emptyLabel={t('workspace.empty')}>
        <div className="card-grid">{records.map((schedule) => <article className="card" key={schedule.id}><h2>{schedule.name}</h2><p className="muted">{schedule.employeeId}</p></article>)}</div>
      </QueryState>
      </div>
    </Page>
  )
}

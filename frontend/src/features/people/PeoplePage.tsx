import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { useAppSelector } from '../../app/hooks'
import { errorMessageKey } from '../../shared/api/problems'
import { Alert } from '../../shared/ui/Alert'
import { Button } from '../../shared/ui/Button'
import { ConfirmDialog } from '../../shared/ui/ConfirmDialog'
import { Page } from '../../shared/ui/Page'
import { Spinner } from '../../shared/ui/Spinner'
import {
  useCreateDepartmentMutation,
  useCreateEmployeeMutation,
  useCreateTeamMutation,
  useGetDepartmentsQuery,
  useGetEmployeesQuery,
  useGetTeamsQuery,
  useResetAttendanceCodeMutation,
  useSetEmployeeStatusMutation,
  useUpdateEmployeeMutation,
  type EmployeeInput,
  type EmployeeRecord,
} from './peopleApi'

const emptyForm = {
  employeeNumber: '',
  name: '',
  email: '',
  phone: '',
  departmentId: '',
  teamId: '',
  managerEmployeeId: '',
  scheduleName: '',
}

export function PeoplePage({ mode = 'manage' }: { mode?: 'manage' | 'view' }) {
  const { t } = useTranslation()
  const permissions = useAppSelector((state) => state.session.permissions)
  const canManage = mode === 'manage' && permissions.includes('employees.manage')
  const canOrganize = mode === 'manage' && permissions.includes('organization.manage')
  const employees = useGetEmployeesQuery()
  const departments = useGetDepartmentsQuery(undefined, { skip: !canOrganize })
  const teams = useGetTeamsQuery(undefined, { skip: !canOrganize })
  const [createEmployee, createState] = useCreateEmployeeMutation()
  const [updateEmployee, updateState] = useUpdateEmployeeMutation()
  const [setStatus, statusState] = useSetEmployeeStatusMutation()
  const [resetCode, resetState] = useResetAttendanceCodeMutation()
  const [createDepartment, departmentState] = useCreateDepartmentMutation()
  const [createTeam, teamState] = useCreateTeamMutation()
  const [editing, setEditing] = useState<EmployeeRecord | null>(null)
  const [form, setForm] = useState(emptyForm)
  const [departmentName, setDepartmentName] = useState('')
  const [teamName, setTeamName] = useState('')
  const [teamDepartmentId, setTeamDepartmentId] = useState('')
  const [confirmDeactivate, setConfirmDeactivate] = useState(false)
  const [issuedCode, setIssuedCode] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const failure = createState.error ?? updateState.error ?? statusState.error ?? resetState.error ?? departmentState.error ?? teamState.error
  const records = employees.data?.data ?? []

  function fill(employee: EmployeeRecord) {
    setEditing(employee)
    setForm({
      employeeNumber: employee.employeeNumber ?? '',
      name: employee.name,
      email: employee.email ?? '',
      phone: employee.phone ?? '',
      departmentId: employee.departmentId ?? '',
      teamId: employee.teamId ?? '',
      managerEmployeeId: employee.managerEmployeeId ?? '',
      scheduleName: employee.schedule?.name ?? '',
    })
  }

  function startCreate() {
    setEditing(null)
    setIssuedCode(null)
    setNotice(null)
    setForm(emptyForm)
  }

  async function onSave(event: FormEvent) {
    event.preventDefault()
    setNotice(null)
    setIssuedCode(null)
    const body: EmployeeInput = {
      employeeNumber: form.employeeNumber,
      name: form.name,
      email: form.email === '' ? null : form.email,
      phone: form.phone === '' ? null : form.phone,
      departmentId: form.departmentId === '' ? null : form.departmentId,
      teamId: form.teamId === '' ? null : form.teamId,
      managerEmployeeId: form.managerEmployeeId === '' ? null : form.managerEmployeeId,
      scheduleName: form.scheduleName === '' ? null : form.scheduleName,
    }
    try {
      const result = editing
        ? await updateEmployee({ id: editing.id, body }).unwrap()
        : await createEmployee(body).unwrap()
      setIssuedCode(result.data.attendanceCode ?? null)
      setNotice(t('people.saved'))
      if (!editing) {
        setForm(emptyForm)
      } else {
        fill(result.data)
      }
    } catch {
      setNotice(null)
    }
  }

  return (
    <Page title={t('people.title')} intro={t('people.intro')}>
      {employees.isLoading ? <p className="toolbar"><Spinner /> {t('people.loading')}</p> : null}
      {employees.error ? <Alert>{t(errorMessageKey(employees.error))}</Alert> : null}
      {failure ? <Alert>{t(errorMessageKey(failure))}</Alert> : null}
      {notice ? <Alert tone="info">{notice}</Alert> : null}
      {issuedCode ? (
        <Alert tone="info">{t('people.codeOnce', { code: issuedCode })}</Alert>
      ) : null}
      <div className="split">
      <div className="card-grid">
        {records.map((employee) => (
          <article className="card" key={employee.id}>
            <h2>{employee.name}</h2>
            <p className="muted">{employee.employeeNumber}</p>
            <p>{t(`people.status.${employee.status}`)}</p>
            <p className="muted">{[employee.departmentName, employee.teamName, employee.managerName].filter(Boolean).join(' · ')}</p>
            {canManage ? <Button type="button" variant="secondary" onClick={() => { setIssuedCode(null); setNotice(null); fill(employee) }}>{t('people.edit')}</Button> : null}
          </article>
        ))}
      </div>
      {canManage || canOrganize ? (<div className="stack">
      {canManage ? <form className="panel" onSubmit={(event) => { void onSave(event) }}>
        <h2>{editing ? t('people.editEmployee') : t('people.createEmployee')}</h2>
        <label className="field">{t('people.number')}<input value={form.employeeNumber} onChange={(event) => setForm({ ...form, employeeNumber: event.target.value })} required /></label>
        <label className="field">{t('people.name')}<input value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} required /></label>
        <label className="field">{t('people.email')}<input value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} /></label>
        <label className="field">{t('people.phone')}<input value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} /></label>
        <label className="field">
          {t('people.department')}
          <select value={form.departmentId} onChange={(event) => setForm({ ...form, departmentId: event.target.value })}>
            <option value="">{t('people.none')}</option>
            {(departments.data?.data ?? []).map((department) => <option key={department.id} value={department.id}>{department.name}</option>)}
          </select>
        </label>
        <label className="field">
          {t('people.team')}
          <select value={form.teamId} onChange={(event) => setForm({ ...form, teamId: event.target.value })}>
            <option value="">{t('people.none')}</option>
            {(teams.data?.data ?? []).map((team) => <option key={team.id} value={team.id}>{team.name}</option>)}
          </select>
        </label>
        <label className="field">
          {t('people.manager')}
          <select value={form.managerEmployeeId} onChange={(event) => setForm({ ...form, managerEmployeeId: event.target.value })}>
            <option value="">{t('people.none')}</option>
            {records.filter((employee) => employee.id !== editing?.id && employee.status === 'active').map((employee) => (
              <option key={employee.id} value={employee.id}>{employee.name}</option>
            ))}
          </select>
        </label>
        <label className="field">{t('people.schedule')}<input value={form.scheduleName} onChange={(event) => setForm({ ...form, scheduleName: event.target.value })} /></label>
        <div className="toolbar">
          <Button type="submit" disabled={createState.isLoading || updateState.isLoading}>{editing ? t('people.save') : t('people.create')}</Button>
          {editing ? <Button type="button" variant="secondary" onClick={startCreate}>{t('people.create')}</Button> : null}
          {editing ? (
            <Button
              type="button"
              variant="secondary"
              disabled={statusState.isLoading}
              onClick={() => {
                if (editing.status === 'active') {
                  setConfirmDeactivate(true)
                  return
                }
                void setStatus({ id: editing.id, status: 'active' }).unwrap().then((result) => fill(result.data)).catch(() => undefined)
              }}
            >
              {editing.status === 'active' ? t('people.deactivate') : t('people.activate')}
            </Button>
          ) : null}
          {editing ? (
            <Button
              type="button"
              variant="secondary"
              disabled={resetState.isLoading}
              onClick={() => {
                void resetCode(editing.id).unwrap().then((result) => {
                  setIssuedCode(result.data.attendanceCode ?? null)
                  fill(result.data)
                }).catch(() => undefined)
              }}
            >
              {editing.attendanceIdentifierSet ? t('people.resetCode') : t('people.generateCode')}
            </Button>
          ) : null}
        </div>
      </form> : null}
      {canOrganize ? (
        <>
          <form className="panel" onSubmit={(event) => {
            event.preventDefault()
            void createDepartment({ name: departmentName }).unwrap().then(() => setDepartmentName('')).catch(() => undefined)
          }}>
            <h2>{t('people.addDepartment')}</h2>
            <label className="field">{t('people.department')}<input value={departmentName} onChange={(event) => setDepartmentName(event.target.value)} required /></label>
            <Button type="submit" disabled={departmentState.isLoading}>{t('people.create')}</Button>
          </form>
          <form className="panel" onSubmit={(event) => {
            event.preventDefault()
            void createTeam({ name: teamName, departmentId: teamDepartmentId === '' ? null : teamDepartmentId }).unwrap().then(() => setTeamName('')).catch(() => undefined)
          }}>
            <h2>{t('people.addTeam')}</h2>
            <label className="field">{t('people.team')}<input value={teamName} onChange={(event) => setTeamName(event.target.value)} required /></label>
            <label className="field">
              {t('people.department')}
              <select value={teamDepartmentId} onChange={(event) => setTeamDepartmentId(event.target.value)}>
                <option value="">{t('people.none')}</option>
                {(departments.data?.data ?? []).map((department) => <option key={department.id} value={department.id}>{department.name}</option>)}
              </select>
            </label>
            <Button type="submit" disabled={teamState.isLoading}>{t('people.create')}</Button>
          </form>
        </>
      ) : null}
      </div>) : null}
      </div>
      <ConfirmDialog
        open={confirmDeactivate && editing !== null}
        title={t('confirm.deactivateTitle')}
        body={t('confirm.deactivateBody')}
        confirmLabel={t('people.deactivate')}
        busy={statusState.isLoading}
        onCancel={() => setConfirmDeactivate(false)}
        onConfirm={() => {
          if (editing === null) {
            return
          }
          void setStatus({ id: editing.id, status: 'inactive' }).unwrap().then((result) => {
            fill(result.data)
            setConfirmDeactivate(false)
          }).catch(() => setConfirmDeactivate(false))
        }}
      />
    </Page>
  )
}

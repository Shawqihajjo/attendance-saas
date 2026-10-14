import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { optionalEmail, passwordText, requiredText } from '../../shared/forms/validate'
import { errorMessageKey } from '../../shared/api/problems'
import { Alert } from '../../shared/ui/Alert'
import { Button } from '../../shared/ui/Button'
import { ConfirmDialog } from '../../shared/ui/ConfirmDialog'
import { Page } from '../../shared/ui/Page'
import { QueryState } from '../../shared/ui/QueryState'
import { useTenantFormat } from '../../shared/theme/useTenantFormat'
import { useGetProfileQuery } from '../admin/profileApi'
import { Pager } from './ReportControls'
import { useCreateUserMutation, useDisableUserMutation, useGetAuditQuery, useGetUsersQuery, useReplaceRolesMutation } from './workspaceApi'

const tenantRoles = ['employee', 'manager', 'hr', 'tenant_admin'] as const

export function ProfilePage() {
  const { t } = useTranslation()
  const profile = useGetProfileQuery()
  const data = profile.data?.data

  return (
    <Page title={t('workspace.profile')} intro={t('workspace.profileIntro')}>
      <QueryState isLoading={profile.isLoading} error={profile.error} empty={!data} emptyLabel={t('workspace.empty')}>
        {data ? (
          <article className="card narrow">
            <h2>{data.displayName}</h2>
            <p>{data.email}</p>
            <p className="muted">{data.roles.join(', ')}</p>
          </article>
        ) : null}
      </QueryState>
    </Page>
  )
}

export function UsersPage() {
  const { t } = useTranslation()
  const users = useGetUsersQuery()
  const [create, createState] = useCreateUserMutation()
  const [disable, disableState] = useDisableUserMutation()
  const [email, setEmail] = useState('')
  const [displayName, setDisplayName] = useState('')
  const [password, setPassword] = useState('')
  const [roles, setRoles] = useState<string[]>(['employee'])
  const [fieldError, setFieldError] = useState<string | null>(null)
  const [pendingDisable, setPendingDisable] = useState<string | null>(null)
  const records = users.data?.data ?? []

  function toggle(role: string) {
    setRoles((current) => current.includes(role) ? current.filter((item) => item !== role) : [...current, role])
  }

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    const invalid = optionalEmail(email) ?? requiredText(displayName) ?? passwordText(password) ?? (roles.length === 0 ? 'workspace.invalidText' : null)
    setFieldError(invalid)
    if (invalid || email.trim() === '') {
      setFieldError(invalid ?? 'workspace.invalidEmail')
      return
    }
    try {
      await create({ email: email.trim(), displayName: displayName.trim(), password, roles }).unwrap()
      setEmail('')
      setDisplayName('')
      setPassword('')
      setRoles(['employee'])
    } catch {
      setFieldError(null)
    }
  }

  return (
    <Page title={t('workspace.users')} intro={t('workspace.usersIntro')}>
      <div className="split form-first">
      <form className="panel" onSubmit={(event) => { void onSubmit(event) }}>
        <label className="field">{t('auth.email')}<input type="email" value={email} autoComplete="off" onChange={(event) => setEmail(event.target.value)} /></label>
        <label className="field">{t('people.name')}<input value={displayName} onChange={(event) => setDisplayName(event.target.value)} /></label>
        <label className="field">{t('auth.password')}<input type="password" value={password} autoComplete="new-password" onChange={(event) => setPassword(event.target.value)} /></label>
        <fieldset className="field">
          <legend>{t('workspace.roles')}</legend>
          {tenantRoles.map((role) => (
            <label key={role} className="toolbar">
              <input type="checkbox" checked={roles.includes(role)} onChange={() => toggle(role)} />
              {t(`workspace.role.${role}`)}
            </label>
          ))}
        </fieldset>
        {fieldError ? <Alert>{t(fieldError)}</Alert> : null}
        {createState.error ? <Alert>{t(errorMessageKey(createState.error))}</Alert> : null}
        <Button type="submit" disabled={createState.isLoading}>{t('people.create')}</Button>
      </form>
      <QueryState isLoading={users.isLoading} error={users.error} empty={records.length === 0} emptyLabel={t('workspace.empty')}>
        <div className="card-grid">
          {records.map((user) => (
            <article className="card" key={user.id}>
              <h2>{user.displayName}</h2>
              <p>{user.email}</p>
              <p className="muted">{user.roles.map((role) => t(`workspace.role.${role}`, { defaultValue: role })).join(', ')} · {t(`workspace.userStatus.${user.status}`, { defaultValue: user.status })}</p>
              {user.status === 'active' ? (
                <Button type="button" variant="secondary" disabled={disableState.isLoading} onClick={() => setPendingDisable(user.id)}>{t('workspace.disable')}</Button>
              ) : null}
            </article>
          ))}
        </div>
      </QueryState>
      </div>
      <ConfirmDialog
        open={pendingDisable !== null}
        title={t('confirm.disableTitle')}
        body={t('confirm.disableBody')}
        confirmLabel={t('workspace.disable')}
        busy={disableState.isLoading}
        onCancel={() => setPendingDisable(null)}
        onConfirm={() => {
          if (pendingDisable === null) {
            return
          }
          void disable(pendingDisable).finally(() => setPendingDisable(null))
        }}
      />
    </Page>
  )
}

export function RolesPage() {
  const { t } = useTranslation()
  const users = useGetUsersQuery()
  const [replace, state] = useReplaceRolesMutation()
  const [userId, setUserId] = useState('')
  const [roles, setRoles] = useState<string[]>([])
  const [fieldError, setFieldError] = useState<string | null>(null)
  const records = users.data?.data ?? []

  function choose(id: string) {
    setUserId(id)
    setRoles(records.find((user) => user.id === id)?.roles ?? [])
  }

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    if (userId === '' || roles.length === 0) {
      setFieldError('workspace.invalidText')
      return
    }
    setFieldError(null)
    try {
      await replace({ id: userId, roles }).unwrap()
    } catch {
      setFieldError(null)
    }
  }

  return (
    <Page title={t('workspace.rolesTitle')} intro={t('workspace.rolesIntro')}>
      <form className="panel" onSubmit={(event) => { void onSubmit(event) }}>
        <label className="field">
          {t('workspace.user')}
          <select value={userId} onChange={(event) => choose(event.target.value)}>
            <option value="">{t('people.none')}</option>
            {records.map((user) => <option key={user.id} value={user.id}>{user.displayName}</option>)}
          </select>
        </label>
        <fieldset className="field">
          <legend>{t('workspace.roles')}</legend>
          {tenantRoles.map((role) => (
            <label key={role} className="toolbar">
              <input
                type="checkbox"
                checked={roles.includes(role)}
                onChange={() => setRoles((current) => current.includes(role) ? current.filter((item) => item !== role) : [...current, role])}
              />
              {t(`workspace.role.${role}`)}
            </label>
          ))}
        </fieldset>
        {fieldError ? <Alert>{t(fieldError)}</Alert> : null}
        {state.error ? <Alert>{t(errorMessageKey(state.error))}</Alert> : null}
        <Button type="submit" disabled={state.isLoading}>{t('workspace.saveRoles')}</Button>
      </form>
    </Page>
  )
}

export function AuditPage() {
  const { t } = useTranslation()
  const format = useTenantFormat()
  const [offset, setOffset] = useState(0)
  const audit = useGetAuditQuery({ limit: 25, offset })
  const records = audit.data?.data ?? []
  const page = audit.data?.meta.page

  return (
    <Page title={t('workspace.audit')} intro={t('workspace.auditIntro')}>
      <QueryState isLoading={audit.isLoading} error={audit.error} empty={records.length === 0} emptyLabel={t('workspace.empty')}>
        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>{t('table.time')}</th>
                <th>{t('table.action')}</th>
                <th>{t('table.result')}</th>
                <th>{t('table.request')}</th>
                <th>{t('table.details')}</th>
              </tr>
            </thead>
            <tbody>
              {records.map((entry, index) => (
                <tr key={`${entry.action}-${entry.actorId}-${index}`}>
                  <td>{entry.occurredAt ? format.dateTime(entry.occurredAt) : ''}</td>
                  <td>{entry.action}</td>
                  <td>{entry.result ? t(`status.${entry.result}`, { defaultValue: entry.result }) : ''}</td>
                  <td>{entry.requestId ?? ''}</td>
                  <td>{entry.metadata}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </QueryState>
      {page ? <Pager page={page} onOffset={setOffset} /> : null}
    </Page>
  )
}

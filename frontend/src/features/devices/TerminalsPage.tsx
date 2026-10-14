import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { errorMessageKey } from '../../shared/api/problems'
import { useTenantFormat } from '../../shared/theme/useTenantFormat'
import { Alert } from '../../shared/ui/Alert'
import { Button } from '../../shared/ui/Button'
import { ConfirmDialog } from '../../shared/ui/ConfirmDialog'
import { Page } from '../../shared/ui/Page'
import { Spinner } from '../../shared/ui/Spinner'
import { useGetBrandingQuery } from '../branding/brandingApi'
import {
  useActivateTerminalMutation,
  useGetDevicePolicyQuery,
  useGetTerminalAuditQuery,
  useGetTerminalsQuery,
  useRegisterTerminalMutation,
  useReplaceDevicePolicyMutation,
  useRevokeTerminalMutation,
  useRotateTerminalMutation,
  useSuspendTerminalMutation,
  type TerminalRecord,
} from './devicesApi'

export function TerminalsPage() {
  const { t } = useTranslation()
  const branding = useGetBrandingQuery()
  const terminals = useGetTerminalsQuery()
  const policy = useGetDevicePolicyQuery()
  const [registerTerminal, registerState] = useRegisterTerminalMutation()
  const [activateTerminal, activateState] = useActivateTerminalMutation()
  const [suspendTerminal, suspendState] = useSuspendTerminalMutation()
  const [revokeTerminal, revokeState] = useRevokeTerminalMutation()
  const [rotateTerminal, rotateState] = useRotateTerminalMutation()
  const [replacePolicy, policyState] = useReplaceDevicePolicyMutation()
  const [selected, setSelected] = useState<string | null>(null)
  const audit = useGetTerminalAuditQuery(selected ?? '', { skip: selected === null })
  const [name, setName] = useState('')
  const [location, setLocation] = useState('')
  const [timezone, setTimezone] = useState('')
  const [certificateRef, setCertificateRef] = useState('')
  const [requireActivation, setRequireActivation] = useState(false)
  const [issuedToken, setIssuedToken] = useState<string | null>(null)
  const [pending, setPending] = useState<{ id: string; action: 'suspend' | 'revoke' } | null>(null)
  const format = useTenantFormat()
  const [networks, setNetworks] = useState<string | null>(null)
  const [ranges, setRanges] = useState<string | null>(null)
  const [versions, setVersions] = useState<string | null>(null)
  const networkValue = networks ?? (policy.data?.data.allowedNetworks ?? []).join(', ')
  const rangeValue = ranges ?? (policy.data?.data.allowedIpRanges ?? []).join(', ')
  const versionValue = versions ?? (policy.data?.data.allowedAppVersions ?? []).join(', ')
  const zone = branding.data?.data.timezone ?? 'UTC'
  const failure = registerState.error ?? activateState.error ?? suspendState.error ?? revokeState.error ?? rotateState.error ?? policyState.error
  const records = terminals.data?.data ?? []

  async function onRegister(event: FormEvent) {
    event.preventDefault()
    setIssuedToken(null)
    try {
      const result = await registerTerminal({ displayName: name, location, timezone, certificateRef, requireActivation }).unwrap()
      setIssuedToken(result.data.enrollmentToken ?? null)
      setName('')
      setLocation('')
      setCertificateRef('')
      setRequireActivation(false)
    } catch {
      setIssuedToken(null)
    }
  }

  async function rotate(id: string) {
    setIssuedToken(null)
    try {
      const result = await rotateTerminal(id).unwrap()
      setIssuedToken(result.data.enrollmentToken ?? null)
      setSelected(id)
    } catch {
      setIssuedToken(null)
    }
  }

  function split(value: string): string[] {
    return value.split(',').map((item) => item.trim()).filter((item) => item !== '')
  }

  return (
    <Page title={t('devices.title')} intro={t('devices.intro')}>
      {terminals.isLoading ? <p className="toolbar"><Spinner /> {t('devices.loading')}</p> : null}
      {terminals.error ? <Alert>{t(errorMessageKey(terminals.error))}</Alert> : null}
      {failure ? <Alert>{t(errorMessageKey(failure))}</Alert> : null}
      {issuedToken ? <Alert tone="info">{t('devices.tokenOnce', { token: issuedToken })}</Alert> : null}
      <div className="split">
      <div className="card-grid">
        {records.map((terminal) => (
          <article className="card" key={terminal.id}>
            <h2>{terminal.displayName}</h2>
            <p>{t(`devices.status.${terminal.status}`, { defaultValue: terminal.status })}</p>
            <p className="muted">{terminal.location || t('devices.noLocation')}</p>
            <p>{t('devices.lastSeen', { value: seen(terminal, zone, t('devices.never'), format.dateTime) })}</p>
            <p className="muted">{terminal.hostname || terminal.osInfo || terminal.appVersion || terminal.deviceUuid}</p>
            <div className="toolbar">
              <Button type="button" variant="secondary" onClick={() => setSelected(terminal.id)}>{t('devices.audit')}</Button>
              {terminal.status === 'registered' || terminal.status === 'suspended' ? (
                <Button type="button" disabled={activateState.isLoading} onClick={() => { void activateTerminal(terminal.id) }}>{t('devices.activate')}</Button>
              ) : null}
              {terminal.status === 'active' || terminal.status === 'registered' ? (
                <Button type="button" variant="secondary" disabled={suspendState.isLoading} onClick={() => setPending({ id: terminal.id, action: 'suspend' })}>{t('devices.suspend')}</Button>
              ) : null}
              {terminal.status !== 'revoked' ? (
                <Button type="button" variant="secondary" disabled={rotateState.isLoading} onClick={() => { void rotate(terminal.id) }}>{t('devices.rotate')}</Button>
              ) : null}
              {terminal.status !== 'revoked' ? (
                <Button type="button" variant="secondary" disabled={revokeState.isLoading} onClick={() => setPending({ id: terminal.id, action: 'revoke' })}>{t('devices.revoke')}</Button>
              ) : null}
            </div>
          </article>
        ))}
      </div>
      <div className="stack">
      {selected ? (
        <section className="panel">
          <h2>{t('devices.auditTitle')}</h2>
          {audit.isLoading ? <Spinner /> : null}
          <ul>
            {(audit.data?.data ?? []).map((entry) => (
              <li key={`${entry.occurredAt}-${entry.action}`}>{format.dateTime(entry.occurredAt, zone)} · {entry.action}{entry.status ? ` · ${t(`devices.status.${entry.status}`, { defaultValue: entry.status })}` : ''}</li>
            ))}
          </ul>
        </section>
      ) : null}
      <form className="panel" onSubmit={(event) => { void onRegister(event) }}>
        <h2>{t('devices.register')}</h2>
        <label className="field">{t('devices.name')}<input value={name} onChange={(event) => setName(event.target.value)} required /></label>
        <label className="field">{t('devices.location')}<input value={location} onChange={(event) => setLocation(event.target.value)} /></label>
        <label className="field">{t('devices.timezone')}<input value={timezone} placeholder={zone} onChange={(event) => setTimezone(event.target.value)} /></label>
        <label className="field">{t('devices.certificate')}<input value={certificateRef} onChange={(event) => setCertificateRef(event.target.value)} /></label>
        <label className="toolbar"><input type="checkbox" checked={requireActivation} onChange={(event) => setRequireActivation(event.target.checked)} /> {t('devices.requireActivation')}</label>
        <Button type="submit" disabled={registerState.isLoading}>{t('devices.register')}</Button>
      </form>
      <form className="panel" onSubmit={(event) => {
        event.preventDefault()
        void replacePolicy({ allowedNetworks: split(networkValue), allowedIpRanges: split(rangeValue), allowedAppVersions: split(versionValue) })
      }}>
        <h2>{t('devices.policy')}</h2>
        <p className="muted">{t('devices.policyHelp')}</p>
        <label className="field">{t('devices.networks')}<input value={networkValue} onChange={(event) => setNetworks(event.target.value)} /></label>
        <label className="field">{t('devices.ranges')}<input value={rangeValue} onChange={(event) => setRanges(event.target.value)} /></label>
        <label className="field">{t('devices.versions')}<input value={versionValue} onChange={(event) => setVersions(event.target.value)} /></label>
        <Button type="submit" variant="secondary" disabled={policyState.isLoading}>{t('devices.savePolicy')}</Button>
      </form>
      </div>
      </div>
      <ConfirmDialog
        open={pending !== null}
        title={t(pending?.action === 'revoke' ? 'confirm.revokeTitle' : 'confirm.suspendTitle')}
        body={t(pending?.action === 'revoke' ? 'confirm.revokeBody' : 'confirm.suspendBody')}
        confirmLabel={t(pending?.action === 'revoke' ? 'devices.revoke' : 'devices.suspend')}
        busy={suspendState.isLoading || revokeState.isLoading}
        onCancel={() => setPending(null)}
        onConfirm={() => {
          if (pending === null) {
            return
          }
          const action = pending.action === 'revoke' ? revokeTerminal(pending.id) : suspendTerminal(pending.id)
          void action.finally(() => setPending(null))
        }}
      />
    </Page>
  )
}

function seen(terminal: TerminalRecord, zone: string, fallback: string, dateTime: (instant: string, timeZone?: string | null) => string): string {
  if (!terminal.lastHeartbeatAt) {
    return fallback
  }
  const formatted = dateTime(terminal.lastHeartbeatAt, zone)

  return formatted === '' ? fallback : formatted
}

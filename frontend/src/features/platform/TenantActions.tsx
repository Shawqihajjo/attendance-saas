import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useAppSelector } from '../../app/hooks'
import { Alert } from '../../shared/ui/Alert'
import { Button } from '../../shared/ui/Button'
import { ConfirmDialog } from '../../shared/ui/ConfirmDialog'
import {
  useActivateTenantMutation,
  useArchiveTenantMutation,
  useDropTenantDatabaseMutation,
  useReactivateTenantMutation,
  useSuspendTenantMutation,
  type TenantSummary,
} from './platformApi'
import { canDropDatabase, expectedStatus, platformErrorKey, rowActions, type TenantLifecycleAction } from './tenantRules'

export function TenantActionButtons({
  tenant,
  onAction,
}: {
  tenant: TenantSummary
  onAction: (tenant: TenantSummary, action: TenantLifecycleAction) => void
}) {
  const { t } = useTranslation()
  const canChange = useAppSelector((state) => state.session.permissions.includes('tenants.lifecycle'))
  if (!canChange) {
    return null
  }

  return (
    <div className="toolbar">
      {rowActions(tenant.status).map((action) => (
        <Button key={action} type="button" variant="secondary" onClick={() => onAction(tenant, action)}>
          {t(`platform.${action}`)}
        </Button>
      ))}
    </div>
  )
}

export function TenantActionDialog({
  tenant,
  action,
  onClose,
  onCompleted,
}: {
  tenant: TenantSummary | null
  action: TenantLifecycleAction | null
  onClose: () => void
  onCompleted: (action: TenantLifecycleAction) => void
}) {
  const { t } = useTranslation()
  const [confirmation, setConfirmation] = useState('')
  const [failure, setFailure] = useState<string | null>(null)
  const submitting = useRef(false)
  const [activate, activateState] = useActivateTenantMutation()
  const [suspend, suspendState] = useSuspendTenantMutation()
  const [reactivate, reactivateState] = useReactivateTenantMutation()
  const [archive, archiveState] = useArchiveTenantMutation()
  const [drop, dropState] = useDropTenantDatabaseMutation()
  const busy = submitting.current || activateState.isLoading || suspendState.isLoading || reactivateState.isLoading || archiveState.isLoading || dropState.isLoading
  const open = tenant !== null && action !== null
  const dropReady = action !== 'drop' || confirmation.trim() === tenant?.slug

  function close() {
    if (busy) {
      return
    }
    setConfirmation('')
    setFailure(null)
    onClose()
  }

  async function confirm() {
    if (!tenant || !action || busy || !dropReady || submitting.current) {
      return
    }
    submitting.current = true
    setFailure(null)
    try {
      const result = await request().unwrap()
      const status = result.data.status
      const removed = action !== 'drop' || result.data.provisioningStatus === 'removed'
      if (status !== expectedStatus(action) || !removed) {
        setFailure('platform.provisionIncomplete')
        return
      }
      setConfirmation('')
      setFailure(null)
      onCompleted(action)
      onClose()
    } catch (error) {
      setFailure(platformErrorKey(error))
    } finally {
      submitting.current = false
    }

    function request() {
      if (!tenant || !action) {
        throw new Error('The tenant action is not selected.')
      }
      if (action === 'activate') {
        return activate({ slug: tenant.slug })
      }
      if (action === 'suspend') {
        return suspend({ slug: tenant.slug })
      }
      if (action === 'reactivate') {
        return reactivate({ slug: tenant.slug })
      }
      if (action === 'archive') {
        return archive({ slug: tenant.slug, confirmation: tenant.slug })
      }

      return drop({ slug: tenant.slug, confirmation: confirmation.trim() })
    }
  }

  return (
    <ConfirmDialog
      open={open}
      title={action ? t(`platform.${action}Title`) : ''}
      body={action ? t(`platform.${action}Body`) : ''}
      confirmLabel={action === 'drop' ? t('platform.dropAction') : action ? t(`platform.${action}`) : ''}
      busy={busy}
      confirmDisabled={!dropReady}
      onCancel={close}
      onConfirm={() => { void confirm() }}
    >
      {action === 'drop' && tenant ? (
        <label className="field">
          {t('platform.typeSlug')}
          <input value={confirmation} autoComplete="off" spellCheck={false} onChange={(event) => setConfirmation(event.target.value)} />
        </label>
      ) : null}
      {failure ? <Alert>{t(failure)}</Alert> : null}
    </ConfirmDialog>
  )
}

export function PermanentDeletion({
  tenant,
  onAction,
}: {
  tenant: TenantSummary
  onAction: (tenant: TenantSummary, action: TenantLifecycleAction) => void
}) {
  const { t } = useTranslation()
  const canChange = useAppSelector((state) => state.session.permissions.includes('tenants.lifecycle'))
  if (!canChange || !canDropDatabase(tenant.status)) {
    return null
  }

  return (
    <section className="danger-zone">
      <h2>{t('platform.dropSection')}</h2>
      <p>{t('platform.dropHelp')}</p>
      <Button type="button" onClick={() => onAction(tenant, 'drop')}>{t('platform.dropAction')}</Button>
    </section>
  )
}

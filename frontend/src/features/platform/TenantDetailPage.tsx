import { useState } from 'react'
import { Link, useLocation, useParams } from 'react-router'
import { useTranslation } from 'react-i18next'
import { useAppSelector } from '../../app/hooks'
import { useTenantFormat } from '../../shared/theme/useTenantFormat'
import { Alert } from '../../shared/ui/Alert'
import { Button } from '../../shared/ui/Button'
import { Page } from '../../shared/ui/Page'
import { QueryState } from '../../shared/ui/QueryState'
import { Spinner } from '../../shared/ui/Spinner'
import { problemFromQueryError } from '../../shared/api/problems'
import { useCheckTenantDatabaseMutation, useGetPlatformAuditQuery, useGetTenantQuery, type TenantSummary } from './platformApi'
import { PermanentDeletion, TenantActionButtons, TenantActionDialog } from './TenantActions'
import { TenantForm } from './TenantForm'
import { TenantQuotaPanel } from './TenantQuotas'
import { platformErrorKey, type TenantLifecycleAction } from './tenantRules'
import { ProvisioningValue, StatusValue } from './PlatformPage'

export function CreateTenantPage() {
  const { t } = useTranslation()

  return (
    <Page
      title={t('platform.createTitle')}
      intro={t('platform.createIntro')}
      actions={<Link className="button secondary" to="/platform">{t('platform.back')}</Link>}
    >
      <TenantForm mode="create" />
    </Page>
  )
}

export function TenantDetailPage() {
  const { t } = useTranslation()
  const format = useTenantFormat()
  const { slug = '' } = useParams()
  const location = useLocation()
  const canChange = useAppSelector((state) => state.session.permissions.includes('tenants.lifecycle'))
  const canAudit = useAppSelector((state) => state.session.permissions.includes('platform.administer'))
  const tenants = useGetTenantQuery(slug, { skip: slug === '' })
  const tenant = tenants.data?.data.slug === slug ? tenants.data.data : undefined
  const missing = problemFromQueryError(tenants.error)?.status === 404
  const audit = useGetPlatformAuditQuery({ tenant: slug, limit: 20, offset: 0 }, { skip: !canAudit || slug === '' })
  const [check, checkState] = useCheckTenantDatabaseMutation()
  const [checkMessage, setCheckMessage] = useState<{ tone: 'danger' | 'info'; key: string } | null>(null)
  const [pending, setPending] = useState<TenantLifecycleAction | null>(null)
  const [done, setDone] = useState<TenantLifecycleAction | null>(null)
  const arrival = noticeFromState(location.state)

  async function onCheck() {
    setCheckMessage(null)
    try {
      const result = await check(slug).unwrap()
      setCheckMessage({ tone: 'info', key: result.data.reachable ? 'platform.reachable' : 'platform.unreachable' })
    } catch (error) {
      setCheckMessage({ tone: 'danger', key: platformErrorKey(error) })
    }
  }

  return (
    <Page
      title={tenant?.legalName ?? t('platform.detailTitle')}
      intro={t('platform.detailIntro')}
      actions={<Link className="button secondary" to="/platform">{t('platform.back')}</Link>}
    >
      {arrival ? <Alert tone="info">{t(arrival)}</Alert> : null}
      {done ? <Alert tone="info">{t(`platform.done.${done}`)}</Alert> : null}
      <QueryState isLoading={tenants.isLoading} error={missing ? undefined : tenants.error} empty={!tenants.isLoading && (missing || (!tenants.error && !tenant))} emptyLabel={t('platform.missing')}>
        {tenant ? (
          <div className="stack">
            <section className="card">
              <h2>{t('platform.general')}</h2>
              <div className="stack">
                <p>{t('platform.identifier')}: {tenant.slug}</p>
                <p>{t('platform.hostname')}: {tenant.hostname ?? t('platform.none')}</p>
                <p>{t('platform.statusFilter')}: <StatusValue status={tenant.status} /></p>
                <p>{t('platform.provisioning')}: <ProvisioningValue status={tenant.provisioningStatus} /></p>
                <p>{t('platform.language')}: {localeList(t, tenant)}</p>
                <p>{t('platform.timezone')}: {tenant.timezone ?? t('platform.none')}</p>
                <p>{t('platform.created')}: {format.dateTime(tenant.createdAt, tenant.timezone)}</p>
                <p>{t('platform.lastActivity')}: {tenant.lastActivityAt ? format.dateTime(tenant.lastActivityAt, tenant.timezone) : t('platform.none')}</p>
              </div>
              <div className="toolbar">
                <TenantActionButtons tenant={tenant} onAction={(_item, action) => { setDone(null); setPending(action) }} />
                {canChange ? (
                  <Button type="button" variant="secondary" disabled={checkState.isLoading} onClick={() => { void onCheck() }}>
                    {checkState.isLoading ? t('platform.checking') : t('platform.check')}
                  </Button>
                ) : null}
              </div>
              {checkState.isLoading ? <p className="toolbar"><Spinner /> {t('platform.checking')}</p> : null}
              {checkMessage ? <Alert tone={checkMessage.tone}>{t(checkMessage.key)}</Alert> : null}
            </section>
            <TenantQuotaPanel slug={tenant.slug} canChange={canChange} />
            {canChange ? (
              <section className="stack">
                <h2>{t('platform.editTitle')}</h2>
                <p className="muted">{t('platform.editIntro')}</p>
                <TenantForm key={tenant.slug} mode="edit" tenant={tenant} />
              </section>
            ) : null}
            <section className="stack">
              <h2>{t('platform.history')}</h2>
              {canAudit ? (
                <QueryState isLoading={audit.isLoading} error={audit.error} empty={(audit.data?.data.length ?? 0) === 0} emptyLabel={t('platform.historyEmpty')}>
                  <div className="stack">
                    {audit.data?.data.map((entry) => (
                      <article className="card" key={`${entry.occurredAt}-${entry.action}-${entry.actorId}`}>
                        <h2>{t(`platform.auditAction.${entry.action}`, { defaultValue: entry.action })}</h2>
                        <p className="muted">
                          {format.dateTime(entry.occurredAt, tenant.timezone)}
                          {' · '}
                          {entry.result ? t(`status.${entry.result}`, { defaultValue: entry.result }) : t('platform.none')}
                          {' · '}
                          {entry.actorType}
                        </p>
                        {typeof entry.metadata.reason === 'string' ? <p>{entry.metadata.reason}</p> : null}
                      </article>
                    ))}
                  </div>
                </QueryState>
              ) : (
                <p className="muted">{t('platform.historyUnavailable')}</p>
              )}
            </section>
            <PermanentDeletion tenant={tenant} onAction={(_item, action) => { setDone(null); setPending(action) }} />
          </div>
        ) : null}
      </QueryState>
      <TenantActionDialog
        tenant={tenant ?? null}
        action={pending}
        onClose={() => setPending(null)}
        onCompleted={setDone}
      />
    </Page>
  )
}

function localeList(t: (key: string) => string, tenant: TenantSummary): string {
  const enabled = tenant.enabledLocales.map((locale) => locale === 'en' || locale === 'sv' ? t(`language.${locale}`) : locale)
  const fallback = tenant.defaultLocale === 'en' || tenant.defaultLocale === 'sv' ? t(`language.${tenant.defaultLocale}`) : tenant.defaultLocale
  if (enabled.length === 0) {
    return fallback ?? t('platform.none')
  }

  return `${fallback ?? t('platform.none')} (${enabled.join(', ')})`
}

function noticeFromState(state: unknown): string | null {
  if (typeof state !== 'object' || state === null || !('notice' in state)) {
    return null
  }
  const notice = (state as { notice?: unknown }).notice
  if (notice === 'platform.createdOk' || notice === 'platform.adminFailed') {
    return notice
  }

  return null
}

import { useState } from 'react'
import { Link } from 'react-router'
import { useTranslation } from 'react-i18next'
import { useAppSelector } from '../../app/hooks'
import { useTenantFormat } from '../../shared/theme/useTenantFormat'
import { Alert } from '../../shared/ui/Alert'
import { Button } from '../../shared/ui/Button'
import { Page } from '../../shared/ui/Page'
import { QueryState } from '../../shared/ui/QueryState'
import { Pager } from '../workspace/ReportControls'
import { useGetTenantsQuery, type TenantSummary } from './platformApi'
import { TenantActionButtons, TenantActionDialog } from './TenantActions'
import { TenantQuotaCells, TenantQuotaSummary } from './TenantQuotas'
import type { TenantLifecycleAction } from './tenantRules'

const limit = 20

export function PlatformPage() {
  const { t } = useTranslation()
  const format = useTenantFormat()
  const canChange = useAppSelector((state) => state.session.permissions.includes('tenants.lifecycle'))
  const [draftQuery, setDraftQuery] = useState('')
  const [q, setQ] = useState('')
  const [status, setStatus] = useState('')
  const [sort, setSort] = useState<'createdAt' | 'legalName' | 'slug' | 'status'>('createdAt')
  const [direction, setDirection] = useState<'asc' | 'desc'>('desc')
  const [offset, setOffset] = useState(0)
  const [pending, setPending] = useState<{ tenant: TenantSummary; action: TenantLifecycleAction } | null>(null)
  const [done, setDone] = useState<TenantLifecycleAction | null>(null)
  const tenants = useGetTenantsQuery({ q, status, sort, direction, limit, offset })
  const rows = tenants.data?.data ?? []
  const page = tenants.data?.meta.page

  function chooseAction(tenant: TenantSummary, action: TenantLifecycleAction) {
    setDone(null)
    setPending({ tenant, action })
  }

  return (
    <Page
      title={t('platform.title')}
      intro={t('platform.intro')}
      actions={canChange ? <Link className="button" to="/platform/new">{t('platform.add')}</Link> : null}
    >
      <form
        className="toolbar"
        onSubmit={(event) => {
          event.preventDefault()
          setQ(draftQuery.trim())
          setOffset(0)
        }}
      >
        <label className="field">
          {t('platform.search')}
          <input value={draftQuery} placeholder={t('platform.searchPlaceholder')} onChange={(event) => setDraftQuery(event.target.value)} />
        </label>
        <label className="field">
          {t('platform.statusFilter')}
          <select value={status} onChange={(event) => { setStatus(event.target.value); setOffset(0) }}>
            <option value="">{t('platform.statusAll')}</option>
            {['provisioning', 'active', 'suspended', 'decommissioned'].map((item) => (
              <option key={item} value={item}>{t(`platform.status.${item}`)}</option>
            ))}
          </select>
        </label>
        <label className="field">
          {t('platform.sort')}
          <select value={sort} onChange={(event) => { setSort(event.target.value as typeof sort); setOffset(0) }}>
            <option value="createdAt">{t('platform.sortCreated')}</option>
            <option value="legalName">{t('platform.sortCompany')}</option>
            <option value="slug">{t('platform.sortIdentifier')}</option>
            <option value="status">{t('platform.sortStatus')}</option>
          </select>
        </label>
        <label className="field">
          {t('platform.direction')}
          <select value={direction} onChange={(event) => { setDirection(event.target.value as 'asc' | 'desc'); setOffset(0) }}>
            <option value="desc">{t('platform.newest')}</option>
            <option value="asc">{t('platform.oldest')}</option>
          </select>
        </label>
        <Button type="submit">{t('platform.search')}</Button>
        <Button type="button" variant="secondary" disabled={tenants.isFetching} onClick={() => { void tenants.refetch() }}>
          {t('platform.refresh')}
        </Button>
      </form>
      {done ? <Alert tone="info">{t(`platform.done.${done}`)}</Alert> : null}
      <QueryState isLoading={tenants.isLoading} error={tenants.error} empty={!tenants.isLoading && !tenants.error && rows.length === 0} emptyLabel={t('platform.empty')}>
        <TenantTable rows={rows} format={format.dateTime} onAction={chooseAction} />
        <TenantCards rows={rows} format={format.dateTime} onAction={chooseAction} />
      </QueryState>
      {page ? <Pager page={page} onOffset={setOffset} /> : null}
      <TenantActionDialog
        tenant={pending?.tenant ?? null}
        action={pending?.action ?? null}
        onClose={() => setPending(null)}
        onCompleted={setDone}
      />
    </Page>
  )
}

function TenantTable({
  rows,
  format,
  onAction,
}: {
  rows: TenantSummary[]
  format: (instant: string, zone?: string | null) => string
  onAction: (tenant: TenantSummary, action: TenantLifecycleAction) => void
}) {
  const { t } = useTranslation()

  return (
    <div className="tenant-table table-wrap">
      <table className="data-table">
        <thead>
          <tr>
            <th>{t('platform.company')}</th>
            <th>{t('platform.identifier')}</th>
            <th>{t('platform.statusFilter')}</th>
            <th>{t('platform.language')}</th>
            <th>{t('platform.timezone')}</th>
            <th>{t('platform.quotaColumn.users')}</th>
            <th>{t('platform.quotaColumn.employees')}</th>
            <th>{t('platform.quotaColumn.devices')}</th>
            <th>{t('platform.quotaColumn.status')}</th>
            <th>{t('platform.provisioning')}</th>
            <th>{t('platform.created')}</th>
            <th>{t('platform.actions')}</th>
          </tr>
        </thead>
        <tbody>
          {rows.map((tenant) => (
            <tr key={tenant.id}>
              <td>{tenant.legalName}</td>
              <td>{tenant.slug}</td>
              <td><StatusValue status={tenant.status} /></td>
              <td>{languageLabel(t, tenant.defaultLocale)}</td>
              <td>{tenant.timezone ?? t('platform.none')}</td>
              <TenantQuotaCells slug={tenant.slug} />
              <td><ProvisioningValue status={tenant.provisioningStatus} /></td>
              <td>{format(tenant.createdAt, tenant.timezone)}</td>
              <td>
                <div className="toolbar">
                  <Link className="button secondary" to={`/platform/${tenant.slug}`}>{t('platform.details')}</Link>
                  <TenantActionButtons tenant={tenant} onAction={onAction} />
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function TenantCards({
  rows,
  format,
  onAction,
}: {
  rows: TenantSummary[]
  format: (instant: string, zone?: string | null) => string
  onAction: (tenant: TenantSummary, action: TenantLifecycleAction) => void
}) {
  const { t } = useTranslation()

  return (
    <div className="tenant-cards">
      {rows.map((tenant) => (
        <article className="card" key={tenant.id}>
          <h2>{tenant.legalName}</h2>
          <p>{tenant.slug}</p>
          <p><StatusValue status={tenant.status} /> <ProvisioningValue status={tenant.provisioningStatus} /></p>
          <p className="muted">{languageLabel(t, tenant.defaultLocale)} · {tenant.timezone ?? t('platform.none')}</p>
          <TenantQuotaSummary slug={tenant.slug} />
          <p className="muted">{format(tenant.createdAt, tenant.timezone)}</p>
          <div className="toolbar">
            <Link className="button secondary" to={`/platform/${tenant.slug}`}>{t('platform.details')}</Link>
            <TenantActionButtons tenant={tenant} onAction={onAction} />
          </div>
        </article>
      ))}
    </div>
  )
}

export function StatusValue({ status }: { status: string }) {
  const { t } = useTranslation()
  const tone = status === 'active' ? 'status status-ok' : status === 'suspended' || status === 'decommissioned' ? 'status status-failed' : 'status'

  return <span className={tone}>{t(`platform.status.${status}`, { defaultValue: status })}</span>
}

export function ProvisioningValue({ status }: { status: string }) {
  const { t } = useTranslation()
  const tone = status === 'ready' ? 'status status-ok' : 'status status-failed'

  return <span className={tone}>{t(`platform.database.${status}`, { defaultValue: status })}</span>
}

function languageLabel(t: (key: string) => string, locale: string | null): string {
  if (locale === 'en' || locale === 'sv') {
    return t(`language.${locale}`)
  }

  return locale ?? t('platform.none')
}

import { useRef, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Alert } from '../../shared/ui/Alert'
import { Button } from '../../shared/ui/Button'
import { ConfirmDialog } from '../../shared/ui/ConfirmDialog'
import { Page } from '../../shared/ui/Page'
import { QueryState } from '../../shared/ui/QueryState'
import { useGetTenantUsageQuery } from '../workspace/workspaceApi'
import { useGetTenantQuotasQuery, useUpdateTenantQuotasMutation, type QuotaMetric, type QuotaReport } from './platformApi'
import { limitIsBelowUsage, overallQuotaLevel, quotaNeedsIncrease, quotaStatusClass, QUOTA_WARNING_RATIO, usagePercentState } from './quotaDisplay'
import { platformErrorKey } from './tenantRules'

type Resource = 'users' | 'employees' | 'devices'

const resources: Resource[] = ['users', 'employees', 'devices']

export function TenantQuotaPanel({ slug, canChange }: { slug: string; canChange: boolean }) {
  const { t } = useTranslation()
  const quotas = useGetTenantQuotasQuery(slug, { skip: slug === '' })
  const report = quotas.data?.data
  const [editing, setEditing] = useState(false)

  return (
    <section className="stack" aria-labelledby="limits-usage">
      <div className="toolbar">
        <h2 id="limits-usage">{t('platform.limitsUsage')}</h2>
        {canChange && report ? (
          <Button type="button" variant="secondary" onClick={() => setEditing(true)}>{t('platform.editLimits')}</Button>
        ) : null}
      </div>
      <p className="muted">{t('platform.limitsUsageIntro', { percent: Math.round(QUOTA_WARNING_RATIO * 100) })}</p>
      <QueryState isLoading={quotas.isLoading} error={quotas.error} empty={!quotas.isLoading && !quotas.error && !report} emptyLabel={t('platform.missing')}>
        {report ? (
          <div className="stack">
            <QuotaCards report={report} canChange={canChange} editing={editing} onIncrease={() => setEditing(true)} />
            {canChange && editing ? (
              <QuotaForm slug={slug} report={report} onClose={() => setEditing(false)} />
            ) : null}
          </div>
        ) : null}
      </QueryState>
    </section>
  )
}

export function TenantUsagePage() {
  const { t } = useTranslation()
  const usage = useGetTenantUsageQuery()

  return (
    <Page title={t('workspace.limitsUsage')} intro={t('workspace.limitsUsageIntro')}>
      <QueryState isLoading={usage.isLoading} error={usage.error} empty={!usage.isLoading && !usage.error && !usage.data} emptyLabel={t('workspace.limitsUsageEmpty')}>
        {usage.data ? (
          <QuotaCards report={usage.data.data} canChange={false} editing={false} guidance onIncrease={() => undefined} />
        ) : null}
      </QueryState>
    </Page>
  )
}

export function TenantQuotaCells({ slug }: { slug: string }) {
  const view = useQuotaSnapshot(slug)

  return (
    <>
      <td>{view.users}</td>
      <td>{view.employees}</td>
      <td>{view.devices}</td>
      <td>{view.status}</td>
    </>
  )
}

export function TenantQuotaSummary({ slug }: { slug: string }) {
  const { t } = useTranslation()
  const view = useQuotaSnapshot(slug)

  return (
    <div className="stack">
      <p>{t('platform.quotaColumn.users')}: {view.users}</p>
      <p>{t('platform.quotaColumn.employees')}: {view.employees}</p>
      <p>{t('platform.quotaColumn.devices')}: {view.devices}</p>
      <p>{t('platform.quotaColumn.status')}: {view.status}</p>
    </div>
  )
}

function useQuotaSnapshot(slug: string) {
  const { t } = useTranslation()
  const quotas = useGetTenantQuotasQuery(slug, { skip: slug === '' })
  const report = quotas.data?.data
  if (quotas.isLoading) {
    return { users: t('platform.quotaPending'), employees: t('platform.quotaPending'), devices: t('platform.quotaPending'), status: t('platform.quotaPending') }
  }
  if (quotas.error || !report) {
    const message = t(platformErrorKey(quotas.error))
    return { users: message, employees: message, devices: message, status: message }
  }

  return {
    users: usageSentence(t, 'users', report.usage.users),
    employees: usageSentence(t, 'employees', report.usage.employees),
    devices: usageSentence(t, 'devices', report.usage.devices),
    status: <span className={quotaStatusClass(overallQuotaLevel(report.usage))}>{t(`platform.quotaStatus.${overallQuotaLevel(report.usage)}`)}</span>,
  }
}

function QuotaCards({
  report,
  canChange,
  editing,
  guidance = false,
  onIncrease,
}: {
  report: QuotaReport
  canChange: boolean
  editing: boolean
  guidance?: boolean
  onIncrease: () => void
}) {
  return (
    <div className="quota-cards">
      {resources.map((resource) => (
        <QuotaCard
          key={resource}
          resource={resource}
          metric={report.usage[resource]}
          canChange={canChange}
          editing={editing}
          guidance={guidance}
          onIncrease={onIncrease}
        />
      ))}
    </div>
  )
}

function QuotaCard({
  resource,
  metric,
  canChange,
  editing,
  guidance,
  onIncrease,
}: {
  resource: Resource
  metric: QuotaMetric
  canChange: boolean
  editing: boolean
  guidance: boolean
  onIncrease: () => void
}) {
  const { t } = useTranslation()
  const remainingLabel = resource === 'devices' ? t('platform.quotaField.devicesRemaining') : t('platform.quotaField.remaining')

  return (
    <article className="card quota-card">
      <h3>{t(`platform.quotaCard.${resource}`)}</h3>
      <p>{usageSentence(t, resource, metric)}</p>
      {!guidance && metric.level === 'over' ? <p>{t('platform.quotaExceeded')}</p> : null}
      {guidance ? <QuotaGuidance resource={resource} level={metric.level} /> : null}
      <dl>
        <div>
          <dt>{t('platform.quotaField.current')}</dt>
          <dd>{countText(t, metric.used)}</dd>
        </div>
        <div>
          <dt>{t('platform.quotaField.maximum')}</dt>
          <dd>{metric.limit === null ? t('platform.unlimited') : countText(t, metric.limit)}</dd>
        </div>
        <div>
          <dt>{remainingLabel}</dt>
          <dd>{metric.limit === null ? t('platform.unlimited') : countText(t, metric.remaining)}</dd>
        </div>
        <div>
          <dt>{t('platform.quotaField.percent')}</dt>
          <dd>{percentLabel(t, metric)}</dd>
        </div>
        <div>
          <dt>{t('platform.quotaField.status')}</dt>
          <dd><span className={quotaStatusClass(metric.level)}>{t(`platform.quotaStatus.${metric.level}`, { defaultValue: metric.level })}</span></dd>
        </div>
      </dl>
      {canChange && !editing && quotaNeedsIncrease(metric.level) ? (
        <Button type="button" variant="secondary" onClick={onIncrease}>{t('platform.increaseLimit')}</Button>
      ) : null}
    </article>
  )
}

function QuotaForm({ slug, report, onClose }: { slug: string; report: QuotaReport; onClose: () => void }) {
  const { t } = useTranslation()
  const [update, state] = useUpdateTenantQuotasMutation()
  const submitting = useRef(false)
  const [usersUnlimited, setUsersUnlimited] = useState(report.maxUsers === null)
  const [employeesUnlimited, setEmployeesUnlimited] = useState(report.maxEmployees === null)
  const [devicesUnlimited, setDevicesUnlimited] = useState(report.maxActiveDevices === null)
  const [maxUsers, setMaxUsers] = useState(fieldValue(report.maxUsers))
  const [maxEmployees, setMaxEmployees] = useState(fieldValue(report.maxEmployees))
  const [maxActiveDevices, setMaxActiveDevices] = useState(fieldValue(report.maxActiveDevices))
  const [failure, setFailure] = useState<string | null>(null)
  const [pending, setPending] = useState<{ maxUsers: number | null; maxEmployees: number | null; maxActiveDevices: number | null } | null>(null)

  function parsedLimits(): { maxUsers: number | null; maxEmployees: number | null; maxActiveDevices: number | null } | undefined {
    const nextUsers = usersUnlimited ? null : parseLimit(maxUsers)
    const nextEmployees = employeesUnlimited ? null : parseLimit(maxEmployees)
    const nextDevices = devicesUnlimited ? null : parseLimit(maxActiveDevices)
    if (nextUsers === undefined || nextEmployees === undefined || nextDevices === undefined) {
      return undefined
    }

    return { maxUsers: nextUsers, maxEmployees: nextEmployees, maxActiveDevices: nextDevices }
  }

  function onSubmit(event: FormEvent) {
    event.preventDefault()
    const body = parsedLimits()
    if (!body) {
      setFailure('platform.quotaInvalid')
      return
    }
    setFailure(null)
    const reduces = limitIsBelowUsage(body.maxUsers, report.usage.users)
      || limitIsBelowUsage(body.maxEmployees, report.usage.employees)
      || limitIsBelowUsage(body.maxActiveDevices, report.usage.devices)
    if (reduces) {
      setPending(body)
      return
    }
    void save(body)
  }

  async function save(body: { maxUsers: number | null; maxEmployees: number | null; maxActiveDevices: number | null }) {
    if (submitting.current) {
      return
    }
    submitting.current = true
    setFailure(null)
    try {
      await update({ slug, body }).unwrap()
      setPending(null)
      onClose()
    } catch (error) {
      setFailure(platformErrorKey(error))
    } finally {
      submitting.current = false
    }
  }

  return (
    <form className="panel" aria-label={t('platform.editLimits')} onSubmit={onSubmit}>
      <fieldset className="stack" disabled={state.isLoading}>
        <LimitEditor
          resource="users"
          used={report.usage.users.used}
          unlimited={usersUnlimited}
          value={maxUsers}
          below={limitIsBelowUsage(usersUnlimited ? null : parseLimit(maxUsers) ?? null, report.usage.users)}
          onUnlimited={setUsersUnlimited}
          onValue={setMaxUsers}
        />
        <LimitEditor
          resource="employees"
          used={report.usage.employees.used}
          unlimited={employeesUnlimited}
          value={maxEmployees}
          below={limitIsBelowUsage(employeesUnlimited ? null : parseLimit(maxEmployees) ?? null, report.usage.employees)}
          onUnlimited={setEmployeesUnlimited}
          onValue={setMaxEmployees}
        />
        <LimitEditor
          resource="devices"
          used={report.usage.devices.used}
          unlimited={devicesUnlimited}
          value={maxActiveDevices}
          below={limitIsBelowUsage(devicesUnlimited ? null : parseLimit(maxActiveDevices) ?? null, report.usage.devices)}
          onUnlimited={setDevicesUnlimited}
          onValue={setMaxActiveDevices}
        />
      </fieldset>
      {failure ? <Alert>{t(failure)}</Alert> : null}
      <div className="toolbar">
        <Button type="submit" disabled={state.isLoading}>{t('platform.saveQuotas')}</Button>
        <Button type="button" variant="secondary" onClick={onClose}>{t('confirm.cancel')}</Button>
      </div>
      <ConfirmDialog
        open={pending !== null}
        title={t('platform.quotaConfirmTitle')}
        body={t('platform.quotaReductionWarning')}
        confirmLabel={t('platform.quotaConfirmAction')}
        busy={state.isLoading}
        onCancel={() => setPending(null)}
        onConfirm={() => { if (pending) { void save(pending) } }}
      />
    </form>
  )
}

function LimitEditor({
  resource,
  used,
  unlimited,
  value,
  below,
  onUnlimited,
  onValue,
}: {
  resource: Resource
  used: number | null
  unlimited: boolean
  value: string
  below: boolean
  onUnlimited: (value: boolean) => void
  onValue: (value: string) => void
}) {
  const { t } = useTranslation()

  return (
    <fieldset className="stack">
      <legend>{t(`platform.quotaCard.${resource}`)}</legend>
      <p>{t('platform.quotaCurrent', { used: countText(t, used) })}</p>
      <label className="field">
        <input type="checkbox" checked={unlimited} onChange={(event) => onUnlimited(event.target.checked)} />
        {t('platform.unlimited')}
      </label>
      <label className="field">
        {t('platform.quotaField.maximum')}
        <input value={value} inputMode="numeric" disabled={unlimited} onChange={(event) => onValue(event.target.value)} />
      </label>
      {below ? <p>{t('platform.quotaReductionWarning')}</p> : null}
    </fieldset>
  )
}

function QuotaGuidance({ resource, level }: { resource: Resource; level: string }) {
  const { t } = useTranslation()
  if (level !== 'warning' && level !== 'reached' && level !== 'over') {
    return null
  }
  const tone = level === 'warning' ? 'Warning' : 'Blocked'

  return (
    <>
      {level === 'over' ? <p>{t('platform.quotaExceeded')}</p> : null}
      <p>{t(`workspace.quotaGuidance.${resource}${tone}`)}</p>
    </>
  )
}

function percentLabel(t: (key: string, options?: Record<string, unknown>) => string, metric: QuotaMetric): string {
  const state = usagePercentState(metric)
  if (state === 'unavailable') {
    return t('platform.quotaUnavailable')
  }
  if (state === 'unlimited') {
    return t('platform.none')
  }
  if (state === 'over') {
    return t('platform.quotaStatus.over')
  }

  return t('platform.quotaPercent', { percent: state })
}

function fieldValue(value: number | null): string {
  return value === null ? '' : String(value)
}

function parseLimit(value: string): number | null | undefined {
  const trimmed = value.trim()
  if (trimmed === '') {
    return undefined
  }
  if (!/^\d+$/.test(trimmed)) {
    return undefined
  }
  const parsed = Number(trimmed)
  if (!Number.isSafeInteger(parsed) || parsed > 1000000) {
    return undefined
  }

  return parsed
}

function countText(t: (key: string) => string, value: number | null): string {
  return value === null ? t('platform.quotaUnavailable') : String(value)
}

function usageSentence(t: (key: string, options?: Record<string, unknown>) => string, resource: Resource, metric: QuotaMetric): string {
  if (metric.used === null) {
    return t('platform.quotaUnavailable')
  }
  if (metric.limit === null) {
    return t('platform.quotaUnlimited', { used: metric.used })
  }

  return t(`platform.quotaSentence.${resource}`, { used: metric.used, limit: metric.limit })
}

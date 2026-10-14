import { useRef, useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router'
import { useTranslation } from 'react-i18next'
import { Alert } from '../../shared/ui/Alert'
import { Button } from '../../shared/ui/Button'
import { Spinner } from '../../shared/ui/Spinner'
import { useCreateTenantMutation, useUpdateTenantMutation, type TenantSummary } from './platformApi'
import { createTenantBody, platformErrorKey, tenantLanguages, updateTenantBody, validateTenantDraft, type TenantDraft } from './tenantRules'

const emptyDraft: TenantDraft = {
  legalName: '',
  slug: '',
  hostname: '',
  timezone: 'Europe/Stockholm',
  defaultLocale: 'en',
  locales: ['en', 'sv'],
  administrator: { email: '', displayName: '', password: '' },
}

export function TenantForm({ mode, tenant }: { mode: 'create' | 'edit'; tenant?: TenantSummary }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [create, createState] = useCreateTenantMutation()
  const [update, updateState] = useUpdateTenantMutation()
  const [draft, setDraft] = useState<TenantDraft>(() => tenant ? draftFromTenant(tenant) : emptyDraft)
  const [fieldError, setFieldError] = useState<string | null>(null)
  const [result, setResult] = useState<{ tone: 'danger' | 'info'; key: string } | null>(null)
  const submitting = useRef(false)
  const busy = createState.isLoading || updateState.isLoading || submitting.current

  function change(patch: Partial<TenantDraft>) {
    setDraft((current) => ({ ...current, ...patch }))
  }

  function toggleLocale(locale: string) {
    setDraft((current) => {
      const locales = current.locales.includes(locale)
        ? current.locales.filter((item) => item !== locale)
        : [...current.locales, locale]
      return { ...current, locales }
    })
  }

  async function onSubmit(event: FormEvent) {
    event.preventDefault()
    const invalid = validateTenantDraft(draft, mode)
    setFieldError(invalid)
    setResult(null)
    if (invalid || busy || submitting.current) {
      return
    }
    submitting.current = true
    try {
      if (mode === 'create') {
        const created = await create(createTenantBody(draft)).unwrap()
        if (created.data.status !== 'active') {
          setResult({ tone: 'danger', key: 'platform.provisionIncomplete' })
          return
        }
        const notice = created.data.administratorCreated === false ? 'platform.adminFailed' : 'platform.createdOk'
        navigate(`/platform/${created.data.slug}`, { state: { notice } })
        return
      }
      if (!tenant) {
        return
      }
      const updated = await update({ slug: tenant.slug, body: updateTenantBody(draft) }).unwrap()
      setDraft(draftFromTenant(updated.data))
      setResult({ tone: 'info', key: 'platform.saved' })
    } catch (error) {
      setResult({ tone: 'danger', key: platformErrorKey(error) })
    } finally {
      submitting.current = false
    }
  }

  return (
    <form className="panel" onSubmit={(event) => { void onSubmit(event) }}>
      <fieldset className="stack" disabled={busy}>
        <label className="field">
          {t('platform.companyName')}
          <input value={draft.legalName} onChange={(event) => change({ legalName: event.target.value })} />
        </label>
        {mode === 'create' ? (
          <>
            <label className="field">
              {t('platform.slug')}
              <input
                value={draft.slug}
                autoCapitalize="none"
                spellCheck={false}
                onChange={(event) => change({ slug: event.target.value.toLowerCase() })}
              />
            </label>
            <p className="muted">{t('platform.slugHelp')}</p>
          </>
        ) : (
          <p>{t('platform.identifier')}: {tenant?.slug}</p>
        )}
        <label className="field">
          {t('platform.domain')}
          <input
            value={draft.hostname}
            autoCapitalize="none"
            spellCheck={false}
            onChange={(event) => change({ hostname: event.target.value })}
          />
        </label>
        <p className="muted">{t('platform.domainHelp')}</p>
        <label className="field">
          {t('platform.defaultLanguage')}
          <select value={draft.defaultLocale} onChange={(event) => change({ defaultLocale: event.target.value })}>
            {tenantLanguages.map((locale) => <option key={locale} value={locale}>{t(`language.${locale}`)}</option>)}
          </select>
        </label>
        <fieldset className="field">
          <legend>{t('platform.enabledLanguages')}</legend>
          {tenantLanguages.map((locale) => (
            <label key={locale} className="toolbar">
              <input type="checkbox" checked={draft.locales.includes(locale)} onChange={() => toggleLocale(locale)} />
              {t(`language.${locale}`)}
            </label>
          ))}
        </fieldset>
        <label className="field">
          {t('platform.timezone')}
          <input value={draft.timezone} spellCheck={false} onChange={(event) => change({ timezone: event.target.value })} />
        </label>
        {mode === 'create' ? (
          <fieldset className="field">
            <legend>{t('platform.administrator')}</legend>
            <p className="muted">{t('platform.administratorHelp')}</p>
            <label className="field">
              {t('auth.email')}
              <input
                type="email"
                autoComplete="off"
                value={draft.administrator.email}
                onChange={(event) => change({ administrator: { ...draft.administrator, email: event.target.value } })}
              />
            </label>
            <label className="field">
              {t('platform.displayName')}
              <input
                value={draft.administrator.displayName}
                onChange={(event) => change({ administrator: { ...draft.administrator, displayName: event.target.value } })}
              />
            </label>
            <label className="field">
              {t('auth.password')}
              <input
                type="password"
                autoComplete="new-password"
                value={draft.administrator.password}
                onChange={(event) => change({ administrator: { ...draft.administrator, password: event.target.value } })}
              />
            </label>
          </fieldset>
        ) : null}
      </fieldset>
      {busy ? (
        <p className="toolbar">
          <Spinner /> {t(mode === 'create' ? 'platform.creating' : 'platform.saving')}
        </p>
      ) : null}
      {fieldError ? <Alert>{t(fieldError)}</Alert> : null}
      {result ? <Alert tone={result.tone}>{t(result.key)}</Alert> : null}
      <Button type="submit" disabled={busy}>{t(mode === 'create' ? 'platform.create' : 'platform.save')}</Button>
    </form>
  )
}

function draftFromTenant(tenant: TenantSummary): TenantDraft {
  return {
    legalName: tenant.legalName,
    slug: tenant.slug,
    hostname: tenant.hostname ?? '',
    timezone: tenant.timezone ?? 'Europe/Stockholm',
    defaultLocale: tenant.defaultLocale ?? 'en',
    locales: tenant.enabledLocales.length > 0 ? tenant.enabledLocales : ['en'],
    administrator: { email: '', displayName: '', password: '' },
  }
}

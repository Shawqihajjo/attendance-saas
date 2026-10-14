import { useEffect, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { useAppSelector } from '../../app/hooks'
import { errorMessageKey } from '../../shared/api/problems'
import { catalogs, type LanguageCode } from '../../shared/i18n/catalog'
import { Alert } from '../../shared/ui/Alert'
import { Button } from '../../shared/ui/Button'
import { Spinner } from '../../shared/ui/Spinner'
import {
  useGetBrandingQuery,
  useUpdateBrandingMutation,
  useUpdateLocalesMutation,
  useUpdatePhotoPolicyMutation,
  useUpdateRegionalMutation,
  useUpdateTimezoneMutation,
} from './brandingApi'

const languages = Object.keys(catalogs) as LanguageCode[]
const dateFormats = ['iso', 'dmy', 'mdy'] as const
const timeFormats = ['24h', '12h'] as const

export function ConfigurationPanel({ section = 'all' }: { section?: 'all' | 'branding' | 'languages' | 'timezone' | 'settings' }) {
  const { t } = useTranslation()
  const branding = useGetBrandingQuery()
  const permissions = useAppSelector((state) => state.session.permissions)
  const configuration = branding.data?.data
  const canBrand = permissions.includes('branding.manage')
  const canLocales = permissions.includes('locales.manage')
  const canTimezone = permissions.includes('timezone.manage')
  const canSettings = permissions.includes('tenant.settings.manage')

  const [companyName, setCompanyName] = useState('')
  const [logoUrl, setLogoUrl] = useState('')
  const [faviconUrl, setFaviconUrl] = useState('')
  const [primary, setPrimary] = useState('')
  const [secondary, setSecondary] = useState('')
  const [accent, setAccent] = useState('')
  const [background, setBackground] = useState('')
  const [text, setText] = useState('')
  const [enabled, setEnabled] = useState<LanguageCode[]>(['en', 'sv'])
  const [defaultLanguage, setDefaultLanguage] = useState<LanguageCode>('en')
  const [timezone, setTimezone] = useState('UTC')
  const [dateFormat, setDateFormat] = useState<(typeof dateFormats)[number]>('iso')
  const [timeFormat, setTimeFormat] = useState<(typeof timeFormats)[number]>('24h')
  const [weekStartsOn, setWeekStartsOn] = useState(1)
  const [photoRequired, setPhotoRequired] = useState(true)
  const [notice, setNotice] = useState<string | null>(null)

  const [saveBranding, brandingState] = useUpdateBrandingMutation()
  const [saveLocales, localesState] = useUpdateLocalesMutation()
  const [saveTimezone, timezoneState] = useUpdateTimezoneMutation()
  const [saveRegional, regionalState] = useUpdateRegionalMutation()
  const [savePhoto, photoState] = useUpdatePhotoPolicyMutation()

  useEffect(() => {
    if (!configuration) {
      return
    }
    setCompanyName(configuration.companyName)
    setLogoUrl(configuration.logoUrl ?? '')
    setFaviconUrl(configuration.faviconUrl ?? '')
    setPrimary(configuration.colors.primary)
    setSecondary(configuration.colors.secondary)
    setAccent(configuration.colors.accent)
    setBackground(configuration.colors.background ?? configuration.colors.surface)
    setText(configuration.colors.text)
    const supported = configuration.supportedLanguages.filter((code): code is LanguageCode => code in catalogs)
    setEnabled(supported.length > 0 ? supported : ['en'])
    setDefaultLanguage(configuration.defaultLanguage in catalogs ? configuration.defaultLanguage as LanguageCode : 'en')
    setTimezone(configuration.timezone)
    setDateFormat(configuration.dateFormat === 'dmy' || configuration.dateFormat === 'mdy' ? configuration.dateFormat : 'iso')
    setTimeFormat(configuration.timeFormat === '12h' ? '12h' : '24h')
    setWeekStartsOn(configuration.weekStartsOn === 0 ? 0 : 1)
    setPhotoRequired(configuration.attendance.photoRequired)
  }, [configuration])

  if (branding.isLoading) {
    return (
      <p className="toolbar">
        <Spinner /> {t('configuration.loading')}
      </p>
    )
  }
  if (!configuration || (!canBrand && !canLocales && !canTimezone && !canSettings)) {
    return null
  }

  const failure = brandingState.error ?? localesState.error ?? timezoneState.error ?? regionalState.error ?? photoState.error

  async function onBranding(event: FormEvent) {
    event.preventDefault()
    setNotice(null)
    try {
      await saveBranding({
        companyName,
        logoUrl: logoUrl === '' ? null : logoUrl,
        faviconUrl: faviconUrl === '' ? null : faviconUrl,
        colors: { primary, secondary, accent, background, text },
      }).unwrap()
      setNotice(t('configuration.saved'))
    } catch {
      setNotice(null)
    }
  }

  async function onLanguages(event: FormEvent) {
    event.preventDefault()
    setNotice(null)
    const locales = enabled.includes(defaultLanguage) ? enabled : [...enabled, defaultLanguage]
    try {
      await saveLocales({ locales, defaultLocale: defaultLanguage }).unwrap()
      setNotice(t('configuration.saved'))
    } catch {
      setNotice(null)
    }
  }

  async function onRegional(event: FormEvent) {
    event.preventDefault()
    setNotice(null)
    try {
      if ((section === 'all' || section === 'timezone') && canTimezone) {
        await saveTimezone({ timezone }).unwrap()
      }
      if ((section === 'all' || section === 'settings') && canSettings) {
        await saveRegional({ dateFormat, timeFormat, weekStartsOn }).unwrap()
        await savePhoto({ photoRequired }).unwrap()
      }
      setNotice(t('configuration.saved'))
    } catch {
      setNotice(null)
    }
  }

  return (
    <section className="stack">
      <h2>{t('configuration.title')}</h2>
      <p className="muted">{t('configuration.intro')}</p>
      {failure ? <Alert>{t(errorMessageKey(failure))}</Alert> : null}
      {notice ? <Alert tone="info">{notice}</Alert> : null}
      {(section === 'all' || section === 'branding') && canBrand ? (
        <form className="panel" onSubmit={(event) => { void onBranding(event) }}>
          <label className="field">
            {t('configuration.companyName')}
            <input value={companyName} onChange={(event) => setCompanyName(event.target.value)} required />
          </label>
          <label className="field">
            {t('configuration.logo')}
            <input value={logoUrl} onChange={(event) => setLogoUrl(event.target.value)} placeholder="https://" />
          </label>
          <label className="field">
            {t('configuration.favicon')}
            <input value={faviconUrl} onChange={(event) => setFaviconUrl(event.target.value)} placeholder="https://" />
          </label>
          <div className="color-grid">
            <ColorField label={t('configuration.primary')} value={primary} onChange={setPrimary} />
            <ColorField label={t('configuration.secondary')} value={secondary} onChange={setSecondary} />
            <ColorField label={t('configuration.accent')} value={accent} onChange={setAccent} />
            <ColorField label={t('configuration.background')} value={background} onChange={setBackground} />
            <ColorField label={t('configuration.text')} value={text} onChange={setText} />
          </div>
          <Button type="submit" disabled={brandingState.isLoading}>{t('configuration.saveBranding')}</Button>
        </form>
      ) : null}
      {(section === 'all' || section === 'languages') && canLocales ? (
        <form className="panel" onSubmit={(event) => { void onLanguages(event) }}>
          <fieldset className="field">
            <legend>{t('configuration.supportedLanguages')}</legend>
            {languages.map((code) => (
              <label key={code} className="toolbar">
                <input
                  type="checkbox"
                  checked={enabled.includes(code)}
                  onChange={(event) => {
                    setEnabled((current) => event.target.checked ? [...current, code] : current.filter((item) => item !== code))
                  }}
                />
                {t(`language.${code}`)}
              </label>
            ))}
          </fieldset>
          <label className="field">
            {t('configuration.defaultLanguage')}
            <select value={defaultLanguage} onChange={(event) => setDefaultLanguage(event.target.value as LanguageCode)}>
              {languages.filter((code) => enabled.includes(code)).map((code) => (
                <option key={code} value={code}>{t(`language.${code}`)}</option>
              ))}
            </select>
          </label>
          <Button type="submit" disabled={localesState.isLoading || enabled.length === 0}>{t('configuration.saveLanguages')}</Button>
        </form>
      ) : null}
      {(section === 'all' || section === 'timezone' || section === 'settings') && (canTimezone || canSettings) ? (
        <form className="panel" onSubmit={(event) => { void onRegional(event) }}>
          {(section === 'all' || section === 'timezone') && canTimezone ? (
            <label className="field">
              {t('configuration.timezone')}
              <input value={timezone} onChange={(event) => setTimezone(event.target.value)} required />
            </label>
          ) : null}
          {(section === 'all' || section === 'settings') && canSettings ? (
            <>
              <label className="field">
                {t('configuration.dateFormat')}
                <select value={dateFormat} onChange={(event) => setDateFormat(event.target.value as (typeof dateFormats)[number])}>
                  {dateFormats.map((format) => <option key={format} value={format}>{t(`dateFormat.${format}`)}</option>)}
                </select>
              </label>
              <label className="field">
                {t('configuration.timeFormat')}
                <select value={timeFormat} onChange={(event) => setTimeFormat(event.target.value as (typeof timeFormats)[number])}>
                  {timeFormats.map((format) => <option key={format} value={format}>{t(`timeFormat.${format}`)}</option>)}
                </select>
              </label>
              <label className="field">
                {t('configuration.weekStartsOn')}
                <select value={weekStartsOn} onChange={(event) => setWeekStartsOn(Number(event.target.value))}>
                  <option value={1}>{t('configuration.monday')}</option>
                  <option value={0}>{t('configuration.sunday')}</option>
                </select>
              </label>
              <label className="toolbar">
                <input type="checkbox" checked={photoRequired} onChange={(event) => setPhotoRequired(event.target.checked)} />
                {t('configuration.photoRequired')}
              </label>
            </>
          ) : null}
          <Button type="submit" disabled={timezoneState.isLoading || regionalState.isLoading || photoState.isLoading}>{t('configuration.saveRegional')}</Button>
        </form>
      ) : null}
    </section>
  )
}

function ColorField({ label, value, onChange }: { label: string; value: string; onChange: (value: string) => void }) {
  return (
    <label className="field">
      {label}
      <input type="color" value={normalizeColor(value)} onChange={(event) => onChange(event.target.value)} />
    </label>
  )
}

function normalizeColor(value: string): string {
  return /^#[0-9A-Fa-f]{6}$/.test(value) ? value.toLowerCase() : '#000000'
}

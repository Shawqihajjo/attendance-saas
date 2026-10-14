import { useTranslation } from 'react-i18next'
import { useGetBrandingQuery } from '../../features/branding/brandingApi'
import { persistLocale } from '../i18n'
import { catalogs, type LanguageCode } from '../i18n/catalog'
import { resolveLocale, type LocaleScope } from '../i18n/locale'

export function LanguageSwitcher({ scope = 'user', presentation = 'select' }: { scope?: LocaleScope, presentation?: 'select' | 'buttons' }) {
  const { i18n, t } = useTranslation()
  const { data } = useGetBrandingQuery()
  const enabled = (data?.data.supportedLanguages ?? Object.keys(catalogs)).filter(
    (code): code is LanguageCode => code in catalogs,
  )
  const tenantDefault = data?.data.defaultLanguage ?? 'en'
  const selected = resolveLocale(i18n.resolvedLanguage ?? i18n.language, enabled, tenantDefault)

  if (presentation === 'buttons') {
    if (enabled.length < 2) {
      return null
    }

    return (
      <div className="kiosk-languages" role="group" aria-label={t('language.label')}>
        {enabled.map((locale) => (
          <button
            key={locale}
            type="button"
            aria-pressed={locale === selected}
            className={locale === selected ? 'is-selected' : undefined}
            onClick={() => persistLocale(locale, scope)}
          >
            {t(`language.${locale}`)}
          </button>
        ))}
      </div>
    )
  }

  return (
    <label className="toolbar">
      <span className="muted">{t('language.label')}</span>
      <select value={selected} onChange={(event) => persistLocale(event.target.value, scope)}>
        {enabled.map((locale) => (
          <option key={locale} value={locale}>
            {t(`language.${locale}`)}
          </option>
        ))}
      </select>
    </label>
  )
}

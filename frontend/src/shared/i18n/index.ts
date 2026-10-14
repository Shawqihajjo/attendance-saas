import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import { catalogs, fallbackLanguage, type LanguageCode } from './catalog'
import { clearLocalePreference, localeStorageKey, readLocalePreference, resolveLocale, writeLocalePreference, type LocaleScope } from './locale'

const initialScope: LocaleScope = window.location.pathname.startsWith('/kiosk') ? 'terminal' : 'user'
const storedLocale = readLocalePreference(initialScope)
const initialLanguage: LanguageCode = storedLocale !== null && storedLocale in catalogs ? storedLocale as LanguageCode : fallbackLanguage

void i18n.use(initReactI18next).init({
  resources: Object.fromEntries(
    Object.entries(catalogs).map(([code, translation]) => [code, { translation }]),
  ),
  lng: initialLanguage,
  fallbackLng: fallbackLanguage,
  supportedLngs: Object.keys(catalogs),
  interpolation: { escapeValue: false },
})

export function applyLocale(locale: string): void {
  if (!(locale in catalogs)) {
    return
  }
  document.documentElement.lang = locale
  void i18n.changeLanguage(locale)
}

export function persistLocale(locale: string, scope: LocaleScope = 'user'): void {
  if (!(locale in catalogs)) {
    return
  }
  writeLocalePreference(scope, locale)
  applyLocale(locale)
}

export function applyResolvedLocale(scope: LocaleScope, enabled: readonly string[], tenantDefault: string): LanguageCode {
  const preference = readLocalePreference(scope)
  const resolved = resolveLocale(preference, enabled, tenantDefault)
  if (preference !== null && preference !== resolved) {
    clearLocalePreference(scope)
  }
  applyLocale(resolved)

  return resolved
}

export { localeStorageKey }

export default i18n

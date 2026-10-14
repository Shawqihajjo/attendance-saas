import { catalogs, fallbackLanguage, type LanguageCode } from './catalog'

export type LocaleScope = 'user' | 'terminal'

const storageKeys: Record<LocaleScope, string> = {
  user: 'locale',
  terminal: 'terminal.locale',
}

export function localeStorageKey(scope: LocaleScope): string {
  return storageKeys[scope]
}

export function readLocalePreference(scope: LocaleScope): string | null {
  return window.localStorage.getItem(localeStorageKey(scope))
}

export function writeLocalePreference(scope: LocaleScope, locale: string): void {
  window.localStorage.setItem(localeStorageKey(scope), locale)
}

export function clearLocalePreference(scope: LocaleScope): void {
  window.localStorage.removeItem(localeStorageKey(scope))
}

export function resolveLocale(preference: string | null, enabled: readonly string[], tenantDefault: string): LanguageCode {
  const known = new Set(Object.keys(catalogs))
  const allowed = enabled.filter((code): code is LanguageCode => known.has(code))
  if (preference && allowed.includes(preference as LanguageCode)) {
    return preference as LanguageCode
  }
  if (allowed.includes(tenantDefault as LanguageCode)) {
    return tenantDefault as LanguageCode
  }

  return allowed[0] ?? fallbackLanguage
}

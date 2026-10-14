import en from './locales/en.json'
import sv from './locales/sv.json'

export const fallbackLanguage = 'en'

export const catalogs = {
  en,
  sv,
} as const

export type LanguageCode = keyof typeof catalogs

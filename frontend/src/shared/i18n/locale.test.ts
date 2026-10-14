import i18n, { applyResolvedLocale, persistLocale } from './index'
import { resolveLocale } from './locale'

describe('language selection', () => {
  it('switches between English and Swedish', async () => {
    persistLocale('en')
    await i18n.changeLanguage('en')
    expect(i18n.t('attendance.clock_in')).toBe('Clock in')
    expect(i18n.t('attendance.verification_failed')).toBe('Verification failed')
    expect(i18n.t('kiosk.reason.network')).toBe('The terminal could not reach the server. Nothing was recorded.')
    expect(i18n.t('kiosk.reason.fingerprint_mismatch')).toBe('The fingerprint did not match.')
    expect(i18n.t('kiosk.reason.capture_failed')).toBe('The capture failed.')

    persistLocale('sv')
    await i18n.changeLanguage('sv')
    expect(i18n.t('attendance.clock_in')).toBe('Stämpla in')
    expect(i18n.t('attendance.lunch_start')).toBe('Starta lunch')
    expect(i18n.t('kiosk.reason.network')).toBe('Terminalen nådde inte servern. Inget registrerades.')
    expect(i18n.t('kiosk.reason.fingerprint_mismatch')).toBe('Fingeravtrycket matchade inte.')
    expect(i18n.t('kiosk.reason.capture_failed')).toBe('Avläsningen misslyckades.')
    expect(i18n.t('table.count', { count: 1, formatted: '1' })).toBe('1 post')
    expect(document.documentElement.lang).toBe('sv')
  })

  it('falls back when the language is unsupported or disabled for the tenant', async () => {
    expect(resolveLocale('fr', ['en', 'sv'], 'sv')).toBe('sv')
    expect(resolveLocale('en', ['sv'], 'sv')).toBe('sv')
    expect(resolveLocale('sv', ['en', 'sv'], 'en')).toBe('sv')
    expect(resolveLocale(null, [], 'de')).toBe('en')

    persistLocale('fr')
    expect(window.localStorage.getItem('locale')).not.toBe('fr')
    await i18n.changeLanguage('fr')
    expect(i18n.t('attendance.clock_out')).toBe('Clock out')
  })

  it('keeps the terminal language separate from the signed-in user preference', async () => {
    persistLocale('en', 'user')
    persistLocale('sv', 'terminal')
    expect(window.localStorage.getItem('locale')).toBe('en')
    expect(window.localStorage.getItem('terminal.locale')).toBe('sv')
    expect(document.documentElement.lang).toBe('sv')

    applyResolvedLocale('user', ['en'], 'en')
    expect(i18n.t('attendance.clock_in')).toBe('Clock in')
    expect(window.localStorage.getItem('terminal.locale')).toBe('sv')

    window.localStorage.setItem('locale', 'de')
    applyResolvedLocale('user', ['sv'], 'sv')
    expect(window.localStorage.getItem('locale')).toBeNull()
    expect(i18n.language.startsWith('sv')).toBe(true)
    expect(i18n.t('nav.logout')).toBe('Logga ut')
  })
})

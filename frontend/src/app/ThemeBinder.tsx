import { useEffect, useState, type ReactNode } from 'react'
import { useLocation } from 'react-router'
import { useTranslation } from 'react-i18next'
import { useGetBrandingQuery } from '../features/branding/brandingApi'
import { applyResolvedLocale } from '../shared/i18n'
import { catalogs, fallbackLanguage } from '../shared/i18n/catalog'
import type { LocaleScope } from '../shared/i18n/locale'
import { applyTenantTheme, readAppearance, resolveAppearance, safeAssetUrl, type Appearance, type BrandingColors } from '../shared/theme/branding'
import { Spinner } from '../shared/ui/Spinner'

const platformColors: BrandingColors = {
  primary: '#1F4B99',
  secondary: '#16356E',
  accent: '#2F80ED',
  surface: '#F4F7FB',
  background: '#F4F7FB',
  text: '#122033',
}

export function ThemeBinder({ children }: { children: ReactNode }) {
  const { t } = useTranslation()
  const location = useLocation()
  const branding = useGetBrandingQuery(undefined, { refetchOnFocus: true, refetchOnReconnect: true })
  const [appearance, setAppearance] = useState<Appearance>(readAppearance)
  const [ready, setReady] = useState(false)
  const scope: LocaleScope = location.pathname.startsWith('/kiosk') ? 'terminal' : 'user'

  useEffect(() => {
    const sync = () => setAppearance(readAppearance())
    window.addEventListener('appearance-change', sync)
    return () => window.removeEventListener('appearance-change', sync)
  }, [])

  useEffect(() => {
    if (branding.isLoading && branding.data === undefined && !branding.isError) {
      return
    }
    const configuration = branding.data?.data
    const colors = configuration?.colors ?? platformColors
    const apply = () => applyTenantTheme(colors, resolveAppearance(appearance))
    apply()
    applyResolvedLocale(
      scope,
      configuration?.supportedLanguages ?? Object.keys(catalogs),
      configuration?.defaultLanguage ?? fallbackLanguage,
    )
    if (configuration) {
      document.title = configuration.companyName
      const favicon = safeAssetUrl(configuration.faviconUrl)
      const existing = document.querySelector<HTMLLinkElement>('link[rel="icon"]')
      if (!favicon) {
        existing?.remove()
      } else if (existing) {
        existing.href = favicon
      } else {
        const link = document.createElement('link')
        link.rel = 'icon'
        link.href = favicon
        document.head.append(link)
      }
    }
    setReady(true)
    if (appearance !== 'system') {
      return
    }
    const media = window.matchMedia('(prefers-color-scheme: dark)')
    media.addEventListener('change', apply)
    return () => media.removeEventListener('change', apply)
  }, [appearance, branding.data, branding.isError, branding.isLoading, scope])

  if (!ready) {
    return (
      <p className="toolbar theme-pending">
        <Spinner /> {t('theme.loading')}
      </p>
    )
  }

  return children
}

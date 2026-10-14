export type BrandingColors = {
  primary: string
  secondary: string
  accent: string
  surface: string
  background?: string
  text: string
}

export type AttendanceConfiguration = {
  photoRequired: boolean
  photoRetentionDays?: number | null
}

export type Branding = {
  companyName: string
  displayName: string
  logoUrl: string | null
  faviconUrl: string | null
  colors: BrandingColors
  defaultLanguage: string
  supportedLanguages: string[]
  defaultLocale: string
  enabledLocales: string[]
  timezone: string
  dateFormat: string
  timeFormat: string
  weekStartsOn: number
  attendance: AttendanceConfiguration
  photoRequired: boolean
}

export type Appearance = 'light' | 'dark' | 'system'

const colorPattern = /^#[0-9A-Fa-f]{6}$/
const appearanceKey = 'appearance'

export function safeAssetUrl(url: string | null | undefined): string | null {
  if (!url || !url.startsWith('https://')) {
    return null
  }

  return url
}

export function readAppearance(): Appearance {
  const stored = window.localStorage.getItem(appearanceKey)
  if (stored === 'light' || stored === 'dark' || stored === 'system') {
    return stored
  }

  return 'system'
}

export function persistAppearance(value: Appearance): void {
  window.localStorage.setItem(appearanceKey, value)
  window.dispatchEvent(new Event('appearance-change'))
}

export function resolveAppearance(preference: Appearance): 'light' | 'dark' {
  if (preference === 'light' || preference === 'dark') {
    return preference
  }
  if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
    return 'dark'
  }

  return 'light'
}

export const styleTokens = {
  radiusControl: '8px',
  radiusCard: '12px',
  radiusKiosk: '16px',
  radiusPill: '999px',
  borderWidth: '1px',
  borderWidthStrong: '2px',
} as const

export function applyTenantTheme(colors: BrandingColors, appearance: 'light' | 'dark' = 'light'): void {
  const root = document.documentElement
  const surface = colors.background && colorPattern.test(colors.background) ? colors.background : colors.surface
  const tokens: Record<string, string> = {
    primary: colors.primary,
    secondary: colors.secondary,
    accent: colors.accent,
    surface,
    text: colors.text,
  }
  for (const [name, value] of Object.entries(tokens)) {
    if (!colorPattern.test(value)) {
      root.style.removeProperty(`--brand-${name}`)
      continue
    }
    root.style.setProperty(`--brand-${name}`, value)
  }
  root.style.setProperty('--radius-control', styleTokens.radiusControl)
  root.style.setProperty('--radius-card', styleTokens.radiusCard)
  root.style.setProperty('--radius-kiosk', styleTokens.radiusKiosk)
  root.style.setProperty('--radius-pill', styleTokens.radiusPill)
  root.style.setProperty('--border-width', styleTokens.borderWidth)
  root.style.setProperty('--border-width-strong', styleTokens.borderWidthStrong)
  const primary = root.style.getPropertyValue('--brand-primary')
  const secondary = root.style.getPropertyValue('--brand-secondary')
  if (colorPattern.test(primary)) {
    root.style.setProperty('--brand-on-primary', ink(primary))
  }
  if (colorPattern.test(secondary)) {
    root.style.setProperty('--brand-on-secondary', ink(secondary))
  }
  root.dataset.appearance = appearance
}

export function applyBranding(colors: BrandingColors): void {
  applyTenantTheme(colors, 'light')
}

function ink(hex: string): string {
  const value = Number.parseInt(hex.slice(1), 16)
  const channels = [(value >> 16) & 255, (value >> 8) & 255, value & 255].map((channel) => {
    const scaled = channel / 255
    return scaled <= 0.03928 ? scaled / 12.92 : ((scaled + 0.055) / 1.055) ** 2.4
  })
  const luminance = 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2]

  return luminance > 0.45 ? '#122033' : '#F7F9FC'
}

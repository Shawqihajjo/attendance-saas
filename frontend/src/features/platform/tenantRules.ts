import { optionalEmail, passwordText, requiredText } from '../../shared/forms/validate'
import { errorMessageKey, problemFromQueryError } from '../../shared/api/problems'
import type { CreateTenantBody, UpdateTenantBody } from './platformApi'

export const tenantLanguages = ['en', 'sv'] as const

export type TenantLifecycleAction = 'activate' | 'suspend' | 'reactivate' | 'archive' | 'drop'

export type TenantDraft = {
  legalName: string
  slug: string
  hostname: string
  timezone: string
  defaultLocale: string
  locales: string[]
  administrator: {
    email: string
    displayName: string
    password: string
  }
}

const slugPattern = /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/

const detailKeys: Record<string, string> = {
  'The tenant slug or hostname is already registered.': 'platform.duplicate',
  'The hostname is already registered.': 'platform.hostnameTaken',
  'Slug cannot be changed.': 'platform.slugLocked',
  'Timezone must be an IANA name.': 'platform.invalidTimezone',
  'Locale is not a supported language.': 'platform.invalidLocale',
  'The default locale must be one of the enabled locales.': 'platform.defaultLocale',
  'Confirmation must match the tenant slug.': 'platform.confirmationMismatch',
  'The tenant database could not be provisioned.': 'platform.provisionFailed',
  'The tenant database is not provisioned.': 'platform.notProvisioned',
  'The tenant is already active.': 'platform.lifecycleConflict',
  'A suspended tenant is reactivated, not activated.': 'platform.lifecycleConflict',
  'Only a provisioning tenant can be activated.': 'platform.lifecycleConflict',
  'Only an active tenant can be suspended.': 'platform.lifecycleConflict',
  'Only a suspended tenant can be reactivated.': 'platform.lifecycleConflict',
  'Only an active or suspended tenant can be decommissioned.': 'platform.lifecycleConflict',
  'Only a decommissioned tenant database can be dropped.': 'platform.lifecycleConflict',
  'Password must be between 12 and 200 characters.': 'workspace.invalidPassword',
  'Email is invalid.': 'workspace.invalidEmail',
  'Display name is invalid.': 'workspace.invalidText',
  'Legal name is invalid.': 'workspace.invalidText',
  'Slug must be a lowercase identifier.': 'platform.invalidSlug',
  'Hostname is invalid.': 'platform.invalidHostname',
  'Hostname is reserved or empty.': 'platform.invalidHostname',
  'A quota must be a non-negative integer or null.': 'platform.quotaInvalid',
}

export function platformErrorKey(error: unknown): string {
  const detail = problemFromQueryError(error)?.detail
  if (detail && detailKeys[detail]) {
    return detailKeys[detail]
  }

  return errorMessageKey(error)
}

export function rowActions(status: string): Array<Exclude<TenantLifecycleAction, 'drop'>> {
  if (status === 'provisioning') {
    return ['activate']
  }
  if (status === 'active') {
    return ['suspend', 'archive']
  }
  if (status === 'suspended') {
    return ['reactivate', 'archive']
  }

  return []
}

export function canDropDatabase(status: string): boolean {
  return status === 'decommissioned'
}

export function expectedStatus(action: TenantLifecycleAction): string {
  if (action === 'suspend') {
    return 'suspended'
  }
  if (action === 'archive' || action === 'drop') {
    return 'decommissioned'
  }

  return 'active'
}

export function validateTenantDraft(draft: TenantDraft, mode: 'create' | 'edit'): string | null {
  const name = requiredText(draft.legalName)
  if (name) {
    return name
  }
  if (mode === 'create' && !slugPattern.test(draft.slug.trim().toLowerCase())) {
    return 'platform.invalidSlug'
  }
  const hostname = draft.hostname.trim().toLowerCase()
  if ((mode === 'edit' || hostname !== '') && !isHostname(hostname)) {
    return 'platform.invalidHostname'
  }
  if (!isTimezone(draft.timezone.trim())) {
    return 'platform.invalidTimezone'
  }
  const locales = uniqueLocales(draft.locales)
  if (locales.length === 0 || !locales.includes(draft.defaultLocale) || !tenantLanguages.includes(draft.defaultLocale as 'en' | 'sv')) {
    return 'platform.invalidLanguages'
  }
  if (mode === 'create') {
    return administratorError(draft.administrator)
  }

  return null
}

export function createTenantBody(draft: TenantDraft): CreateTenantBody {
  const body: CreateTenantBody = {
    legalName: draft.legalName.trim(),
    slug: draft.slug.trim().toLowerCase(),
    timezone: draft.timezone.trim(),
    defaultLocale: draft.defaultLocale,
    locales: uniqueLocales(draft.locales),
  }
  const hostname = draft.hostname.trim().toLowerCase()
  if (hostname !== '') {
    body.hostname = hostname
  }
  const email = draft.administrator.email.trim()
  if (email !== '' || draft.administrator.displayName.trim() !== '' || draft.administrator.password !== '') {
    body.administrator = {
      email,
      displayName: draft.administrator.displayName.trim(),
      password: draft.administrator.password,
    }
  }

  return body
}

export function updateTenantBody(draft: TenantDraft): UpdateTenantBody {
  return {
    legalName: draft.legalName.trim(),
    hostname: draft.hostname.trim().toLowerCase(),
    timezone: draft.timezone.trim(),
    defaultLocale: draft.defaultLocale,
    locales: uniqueLocales(draft.locales),
  }
}

function administratorError(administrator: TenantDraft['administrator']): string | null {
  const started = administrator.email.trim() !== '' || administrator.displayName.trim() !== '' || administrator.password !== ''
  if (!started) {
    return null
  }
  if (administrator.email.trim() === '') {
    return 'workspace.invalidEmail'
  }

  return optionalEmail(administrator.email) ?? requiredText(administrator.displayName) ?? passwordText(administrator.password)
}

function uniqueLocales(locales: string[]): string[] {
  return [...new Set(locales.filter((locale) => tenantLanguages.includes(locale as 'en' | 'sv')))]
}

function isHostname(hostname: string): boolean {
  if (hostname === '' || hostname === 'localhost' || hostname.includes(' ') || !hostname.includes('.')) {
    return false
  }

  return /^[a-z0-9.-]+$/.test(hostname) && !hostname.includes('..')
}

function isTimezone(timezone: string): boolean {
  if (timezone === '') {
    return false
  }
  try {
    Intl.DateTimeFormat('en', { timeZone: timezone })
    return true
  } catch {
    return false
  }
}

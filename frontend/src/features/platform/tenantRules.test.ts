import { createTenantBody, platformErrorKey, rowActions, updateTenantBody, validateTenantDraft, type TenantDraft } from './tenantRules'

const draft: TenantDraft = {
  legalName: 'Northwind AB',
  slug: 'northwind',
  hostname: '',
  timezone: 'Europe/Stockholm',
  defaultLocale: 'sv',
  locales: ['sv'],
  administrator: { email: '', displayName: '', password: '' },
}

describe('tenant form rules', () => {
  it('accepts a tenant without a domain or administrator and omits those fields', () => {
    expect(validateTenantDraft(draft, 'create')).toBeNull()
    const body = createTenantBody(draft)
    expect(body.slug).toBe('northwind')
    expect(body.hostname).toBeUndefined()
    expect(body.administrator).toBeUndefined()
    expect('plan' in body).toBe(false)
    expect('password' in body).toBe(false)
  })

  it('rejects a bad identifier, a partial administrator, and a default language that is not enabled', () => {
    expect(validateTenantDraft({ ...draft, slug: 'North Wind' }, 'create')).toBe('platform.invalidSlug')
    expect(validateTenantDraft({
      ...draft,
      administrator: { email: 'ada@example.com', displayName: '', password: 'long-enough-pass' },
    }, 'create')).toBe('workspace.invalidText')
    expect(validateTenantDraft({ ...draft, defaultLocale: 'en', locales: ['sv'] }, 'create')).toBe('platform.invalidLanguages')
  })

  it('keeps the identifier out of an edit and requires the current domain', () => {
    expect(validateTenantDraft({ ...draft, hostname: '' }, 'edit')).toBe('platform.invalidHostname')
    expect(updateTenantBody({ ...draft, hostname: 'North.Example' }).hostname).toBe('north.example')
    expect('slug' in updateTenantBody(draft)).toBe(false)
  })

  it('offers actions that match the tenant status and keeps deletion off the ordinary list', () => {
    expect(rowActions('provisioning')).toEqual(['activate'])
    expect(rowActions('active')).toEqual(['suspend', 'archive'])
    expect(rowActions('suspended')).toEqual(['reactivate', 'archive'])
    expect(rowActions('decommissioned')).toEqual([])
  })

  it('maps a duplicate registration and a failed request to translated errors', () => {
    expect(platformErrorKey({
      status: 409,
      data: { code: 'http_error', title: 'Request error', status: 409, detail: 'The tenant slug or hostname is already registered.' },
    })).toBe('platform.duplicate')
    expect(platformErrorKey({ status: 'FETCH_ERROR' })).toBe('errors.network')
  })
})

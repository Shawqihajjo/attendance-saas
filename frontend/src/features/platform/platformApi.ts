import { baseApi } from '../../shared/api/baseApi'
import type { PageInfo } from '../workspace/workspaceApi'

export type TenantStatus = 'provisioning' | 'active' | 'suspended' | 'decommissioned'
export type ProvisioningStatus = 'ready' | 'incomplete' | 'removed'

export type TenantSummary = {
  id: string
  legalName: string
  slug: string
  hostname: string | null
  status: TenantStatus | string
  defaultLocale: string | null
  enabledLocales: string[]
  timezone: string | null
  createdAt: string
  provisioningStatus: ProvisioningStatus | string
  lastActivityAt: string | null
  administratorCreated?: boolean
}

export type TenantListArgs = {
  q: string
  status: string
  sort: 'createdAt' | 'legalName' | 'slug' | 'status'
  direction: 'asc' | 'desc'
  limit: number
  offset: number
}

export type TenantListResponse = {
  data: TenantSummary[]
  meta: { page?: PageInfo }
}

export type TenantResponse = {
  data: TenantSummary
  meta: Record<string, unknown>
}

export type CreateTenantBody = {
  legalName: string
  slug: string
  hostname?: string
  timezone: string
  defaultLocale: string
  locales: string[]
  administrator?: {
    email: string
    displayName: string
    password: string
  }
}

export type UpdateTenantBody = {
  legalName: string
  hostname: string
  timezone: string
  defaultLocale: string
  locales: string[]
}

export type PlatformAuditEntry = {
  occurredAt: string
  actorType: string
  actorId: string
  action: string
  targetType: string
  targetId: string
  tenantId: string | null
  result: string | null
  metadata: Record<string, string | number | boolean | null>
}

export type PlatformAuditResponse = {
  data: PlatformAuditEntry[]
  meta: { page?: PageInfo }
}

export type QuotaMetric = {
  used: number | null
  limit: number | null
  remaining: number | null
  level: string
  overLimit: boolean
}

export type QuotaReport = {
  maxUsers: number | null
  maxEmployees: number | null
  maxActiveDevices: number | null
  usage: {
    users: QuotaMetric
    employees: QuotaMetric
    devices: QuotaMetric
  }
}

export type DatabaseCheckResponse = {
  data: {
    reachable: boolean
    tenant: TenantSummary
  }
}

function tenantTags(slug: string): Array<'Tenants' | { type: 'PlatformAudit'; id: string }> {
  return ['Tenants', { type: 'PlatformAudit', id: slug }]
}

export const platformApi = baseApi.injectEndpoints({
  endpoints: (builder) => ({
    getTenants: builder.query<TenantListResponse, TenantListArgs>({
      query: (args) => {
        const params = new URLSearchParams()
        if (args.q !== '') {
          params.set('q', args.q)
        }
        if (args.status !== '') {
          params.set('status', args.status)
        }
        params.set('sort', args.sort)
        params.set('direction', args.direction)
        params.set('limit', String(args.limit))
        params.set('offset', String(args.offset))
        return `/platform/tenants?${params.toString()}`
      },
      providesTags: ['Tenants'],
    }),
    getTenant: builder.query<TenantResponse, string>({
      query: (slug) => `/platform/tenants/${encodeURIComponent(slug)}`,
      providesTags: (_result, _error, slug) => [{ type: 'Tenants', id: slug }],
    }),
    getPlatformAudit: builder.query<PlatformAuditResponse, { tenant: string; limit: number; offset: number }>({
      query: ({ tenant, limit, offset }) =>
        `/platform/audit?tenant=${encodeURIComponent(tenant)}&limit=${limit}&offset=${offset}`,
      providesTags: (_result, _error, arg) => [{ type: 'PlatformAudit', id: arg.tenant }],
    }),
    createTenant: builder.mutation<TenantResponse, CreateTenantBody>({
      query: (body) => ({ url: '/platform/tenants', method: 'POST', body }),
      invalidatesTags: ['Tenants'],
    }),
    updateTenant: builder.mutation<TenantResponse, { slug: string; body: UpdateTenantBody }>({
      query: ({ slug, body }) => ({
        url: `/platform/tenants/${encodeURIComponent(slug)}`,
        method: 'PATCH',
        body,
      }),
      invalidatesTags: (_result, _error, arg) => tenantTags(arg.slug),
    }),
    activateTenant: builder.mutation<TenantResponse, { slug: string }>({
      query: ({ slug }) => ({ url: `/platform/tenants/${encodeURIComponent(slug)}/activate`, method: 'POST', body: {} }),
      invalidatesTags: (_result, _error, arg) => tenantTags(arg.slug),
    }),
    suspendTenant: builder.mutation<TenantResponse, { slug: string }>({
      query: ({ slug }) => ({ url: `/platform/tenants/${encodeURIComponent(slug)}/suspend`, method: 'POST', body: {} }),
      invalidatesTags: (_result, _error, arg) => tenantTags(arg.slug),
    }),
    reactivateTenant: builder.mutation<TenantResponse, { slug: string }>({
      query: ({ slug }) => ({ url: `/platform/tenants/${encodeURIComponent(slug)}/reactivate`, method: 'POST', body: {} }),
      invalidatesTags: (_result, _error, arg) => tenantTags(arg.slug),
    }),
    archiveTenant: builder.mutation<TenantResponse, { slug: string; confirmation: string }>({
      query: ({ slug, confirmation }) => ({
        url: `/platform/tenants/${encodeURIComponent(slug)}/archive`,
        method: 'POST',
        body: { confirmation },
      }),
      invalidatesTags: (_result, _error, arg) => tenantTags(arg.slug),
    }),
    dropTenantDatabase: builder.mutation<TenantResponse, { slug: string; confirmation: string }>({
      query: ({ slug, confirmation }) => ({
        url: `/platform/tenants/${encodeURIComponent(slug)}/drop-database`,
        method: 'POST',
        body: { confirmation },
      }),
      invalidatesTags: (_result, _error, arg) => tenantTags(arg.slug),
    }),
    getTenantQuotas: builder.query<{ data: QuotaReport }, string>({
      query: (slug) => `/platform/tenants/${encodeURIComponent(slug)}/quotas`,
      providesTags: (_result, _error, slug) => [{ type: 'Quotas', id: slug }],
    }),
    updateTenantQuotas: builder.mutation<{ data: QuotaReport }, { slug: string; body: { maxUsers: number | null; maxEmployees: number | null; maxActiveDevices: number | null } }>({
      query: ({ slug, body }) => ({
        url: `/platform/tenants/${encodeURIComponent(slug)}/quotas`,
        method: 'PATCH',
        body,
      }),
      invalidatesTags: (_result, _error, arg) => [{ type: 'Quotas', id: arg.slug }, 'PlatformAudit'],
    }),
    checkTenantDatabase: builder.mutation<DatabaseCheckResponse, string>({
      query: (slug) => ({
        url: `/platform/tenants/${encodeURIComponent(slug)}/database/check`,
        method: 'POST',
        body: {},
      }),
      invalidatesTags: (_result, _error, slug) => tenantTags(slug),
    }),
  }),
})

export const {
  useGetTenantsQuery,
  useGetTenantQuery,
  useGetTenantQuotasQuery,
  useGetPlatformAuditQuery,
  useCreateTenantMutation,
  useUpdateTenantMutation,
  useActivateTenantMutation,
  useSuspendTenantMutation,
  useReactivateTenantMutation,
  useArchiveTenantMutation,
  useDropTenantDatabaseMutation,
  useCheckTenantDatabaseMutation,
  useUpdateTenantQuotasMutation,
} = platformApi

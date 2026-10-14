import { baseApi } from '../../shared/api/baseApi'

export type TerminalRecord = {
  id: string
  deviceUuid: string
  displayName: string
  location: string | null
  status: string
  timezone: string | null
  credentialRef: string | null
  certificateRef: string | null
  macAddress: string | null
  lastPeerIp: string | null
  networkName: string | null
  hostname: string | null
  osInfo: string | null
  appVersion: string | null
  lastHeartbeatAt: string | null
  createdAt: string
  updatedAt: string
  registeredAt: string | null
  suspendedAt: string | null
  revokedAt: string | null
  activationRequired: boolean
  enrollmentToken?: string
  expiresAt?: string
}

export type DevicePolicy = {
  allowedNetworks: string[]
  allowedIpRanges: string[]
  allowedAppVersions: string[]
}

export type DeviceAuditEntry = {
  occurredAt: string
  action: string
  actorType: string
  actorId: string
  status: string | null
}

type TerminalResponse = { data: TerminalRecord }
type TerminalListResponse = { data: TerminalRecord[] }
type PolicyResponse = { data: DevicePolicy }
type AuditResponse = { data: DeviceAuditEntry[] }

export const devicesApi = baseApi.injectEndpoints({
  endpoints: (builder) => ({
    getTerminals: builder.query<TerminalListResponse, void>({
      query: () => '/hr/terminals',
      providesTags: ['Terminals'],
    }),
    getDevicePolicy: builder.query<PolicyResponse, void>({
      query: () => '/hr/device-policy',
      providesTags: ['DevicePolicy'],
    }),
    getTerminalAudit: builder.query<AuditResponse, string>({
      query: (id) => `/hr/terminals/${id}/audit`,
      providesTags: ['TerminalAudit'],
    }),
    registerTerminal: builder.mutation<TerminalResponse, { displayName: string, location: string, timezone: string, certificateRef: string, requireActivation: boolean }>({
      query: (body) => ({ url: '/hr/terminals', method: 'POST', body: {
        displayName: body.displayName,
        location: body.location === '' ? null : body.location,
        timezone: body.timezone === '' ? null : body.timezone,
        certificateRef: body.certificateRef === '' ? null : body.certificateRef,
        requireActivation: body.requireActivation,
      } }),
      invalidatesTags: ['Terminals'],
    }),
    activateTerminal: builder.mutation<TerminalResponse, string>({
      query: (id) => ({ url: `/hr/terminals/${id}/activate`, method: 'POST' }),
      invalidatesTags: ['Terminals', 'TerminalAudit', 'Quotas'],
    }),
    suspendTerminal: builder.mutation<TerminalResponse, string>({
      query: (id) => ({ url: `/hr/terminals/${id}/suspend`, method: 'POST' }),
      invalidatesTags: ['Terminals', 'TerminalAudit', 'Quotas'],
    }),
    revokeTerminal: builder.mutation<TerminalResponse, string>({
      query: (id) => ({ url: `/hr/terminals/${id}/revoke`, method: 'POST' }),
      invalidatesTags: ['Terminals', 'TerminalAudit', 'Quotas'],
    }),
    rotateTerminal: builder.mutation<TerminalResponse, string>({
      query: (id) => ({ url: `/hr/terminals/${id}/credentials`, method: 'POST' }),
      invalidatesTags: ['Terminals', 'TerminalAudit'],
    }),
    replaceDevicePolicy: builder.mutation<PolicyResponse, DevicePolicy>({
      query: (body) => ({ url: '/hr/device-policy', method: 'PUT', body }),
      invalidatesTags: ['DevicePolicy'],
    }),
  }),
})

export const {
  useGetTerminalsQuery,
  useGetDevicePolicyQuery,
  useGetTerminalAuditQuery,
  useRegisterTerminalMutation,
  useActivateTerminalMutation,
  useSuspendTerminalMutation,
  useRevokeTerminalMutation,
  useRotateTerminalMutation,
  useReplaceDevicePolicyMutation,
} = devicesApi

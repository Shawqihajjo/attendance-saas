import { baseApi } from '../../shared/api/baseApi'
import type { QuotaReport } from '../platform/platformApi'

export type AttendanceRecord = {
  id: string
  employeeId: string
  employeeName?: string | null
  eventType: string
  deviceId: string | null
  occurredAt: string
  displayedAt: string
  timezone: string
}

export type PageInfo = {
  limit: number
  offset: number
  total: number
}

export type InstantStamp = {
  occurredAt: string
  displayedAt: string
}

export type DashboardFilters = {
  display?: string
  timezone?: string
  browserTimezone?: string
  date?: string
  limit?: number
  offset?: number
}

export type MyDashboard = {
  timezone: string | null
  date: string | null
  state: string
  clockIn: InstantStamp | null
  clockOut: InstantStamp | null
  lunches: Array<{ start: InstantStamp, end: InstantStamp | null, seconds: number }>
  workedSeconds: number
  missingClockOut: boolean
  history: {
    records: AttendanceRecord[]
    page: PageInfo
  }
}

export type DashboardPerson = {
  id: string
  name: string
  since: InstantStamp | null
}

export type HrDashboard = {
  timezone: string
  date: string
  counts: { working: number, onLunch: number, off: number }
  working: DashboardPerson[]
  onLunch: DashboardPerson[]
  off: DashboardPerson[]
  exceptions: Array<{ id: string, name: string, kind: string, since: InstantStamp | null }>
  exceptionCount: number
  daily: {
    records: Array<{
      employeeId: string
      name: string
      state: string
      clockIn: InstantStamp | null
      clockOut: InstantStamp | null
      workedSeconds: number
      lunchSeconds: number
      missingClockOut: boolean
    }>
    page: PageInfo
  }
  corrections: { pending: number }
}

export type ReportFilters = {
  display: string
  timezone?: string
  browserTimezone?: string
  from?: string
  to?: string
  employeeId?: string
  teamId?: string
  departmentId?: string
  eventType?: string
  status?: string
  limit: number
  offset: number
}

export type AttendanceResponse = {
  data: {
    records: AttendanceRecord[]
    timezone: string | null
    page: PageInfo
  }
}

export type PageArgs = {
  limit?: number
  offset?: number
  date?: string
}

export type CorrectionEvent = {
  id: string
  eventType: string
  occurredAt: string
  origin: string
}

export type CorrectionRecord = {
  id: string
  employeeId: string
  employeeName: string | null
  requestedByUserId: string
  reason: string
  comment: string | null
  supportingInformation: string | null
  localDate: string | null
  localTime: string | null
  requestedAction: string | null
  requestedOccurredAt: string | null
  originalEvents: CorrectionEvent[]
  status: string
  decidedByUserId: string | null
  decisionComment: string | null
  createdAt: string
  decidedAt: string | null
}

export type ScheduleRecord = {
  id: string
  employeeId: string
  name: string
}

export type AuditRecord = {
  action: string
  actorId: string
  metadata: string
  occurredAt?: string
  requestId?: string | null
  result?: string | null
  clientIp?: string | null
  deviceId?: string | null
  targetType?: string
  targetId?: string
}

export type UserRecord = {
  id: string
  email: string
  displayName: string
  status: string
  roles: string[]
}

export const workspaceApi = baseApi.injectEndpoints({
  endpoints: (builder) => ({
    getMyAttendance: builder.query<AttendanceResponse, PageArgs | void>({
      query: (params) => ({ url: '/me/attendance', params: params ?? {} }),
      providesTags: ['Attendance'],
    }),
    getReviewedAttendance: builder.query<AttendanceResponse, PageArgs | void>({
      query: (params) => ({ url: '/hr/attendance', params: params ?? {} }),
      providesTags: ['Attendance'],
    }),
    getMyDashboard: builder.query<{ data: MyDashboard }, DashboardFilters>({
      query: (params) => ({ url: '/me/dashboard', params }),
      providesTags: ['Attendance'],
    }),
    getHrDashboard: builder.query<{ data: HrDashboard }, DashboardFilters>({
      query: (params) => ({ url: '/hr/dashboard', params }),
      providesTags: ['Attendance', 'Corrections'],
    }),
    getReports: builder.query<{ data: { scope: string; timezone: string | null; records: AttendanceRecord[]; page: PageInfo } }, ReportFilters>({
      query: (params) => ({ url: '/hr/reports', params }),
      providesTags: ['Reports'],
    }),
    getMyCorrections: builder.query<{ data: CorrectionRecord[]; meta: { page?: PageInfo } }, PageArgs | void>({
      query: (params) => ({ url: '/me/attendance-corrections', params: params ?? {} }),
      providesTags: ['Corrections'],
    }),
    requestCorrection: builder.mutation<{ data: { id: string; status: string } }, { localDate: string; localTime: string; action: string; reason: string; comment?: string; supportingInformation?: string }>({
      query: (body) => ({ url: '/me/attendance-corrections', method: 'POST', body }),
      invalidatesTags: ['Corrections'],
    }),
    getCorrections: builder.query<{ data: CorrectionRecord[]; meta: { page?: PageInfo } }, PageArgs | void>({
      query: (params) => ({ url: '/hr/attendance-corrections', params: params ?? {} }),
      providesTags: ['Corrections'],
    }),
    decideCorrection: builder.mutation<{ data: { id: string; status: string } }, { id: string; decision: 'approved' | 'rejected'; comment?: string }>({
      query: ({ id, decision, comment }) => ({ url: `/hr/attendance-corrections/${id}/decision`, method: 'POST', body: { decision, comment } }),
      invalidatesTags: ['Corrections', 'Attendance', 'Reports'],
    }),
    getSchedules: builder.query<{ data: ScheduleRecord[] }, void>({
      query: () => '/hr/schedules',
      providesTags: ['Schedules'],
    }),
    createSchedule: builder.mutation<{ data: ScheduleRecord }, { employeeId: string; name: string }>({
      query: (body) => ({ url: '/hr/schedules', method: 'POST', body }),
      invalidatesTags: ['Schedules'],
    }),
    getAudit: builder.query<{ data: AuditRecord[]; meta: { page?: PageInfo } }, PageArgs | void>({
      query: (params) => ({ url: '/hr/audit', params: params ?? {} }),
      keepUnusedDataFor: 0,
      providesTags: ['Audit'],
    }),
    getTenantUsage: builder.query<{ data: QuotaReport }, void>({
      query: () => '/tenant/usage',
      providesTags: ['Quotas'],
    }),
    getUsers: builder.query<{ data: UserRecord[] }, void>({
      query: () => '/hr/users',
      providesTags: ['Users'],
    }),
    createUser: builder.mutation<{ data: UserRecord }, { email: string; displayName: string; password: string; roles: string[] }>({
      query: (body) => ({ url: '/hr/users', method: 'POST', body }),
      invalidatesTags: ['Users', 'Quotas'],
    }),
    replaceRoles: builder.mutation<{ data: { id: string; roles: string[] } }, { id: string; roles: string[] }>({
      query: ({ id, roles }) => ({ url: `/hr/users/${id}/roles`, method: 'PUT', body: { roles } }),
      invalidatesTags: ['Users'],
    }),
    disableUser: builder.mutation<{ data: { id: string; status: string } }, string>({
      query: (id) => ({ url: `/hr/users/${id}/disable`, method: 'POST' }),
      invalidatesTags: ['Users', 'Quotas'],
    }),
  }),
})

export const {
  useCreateScheduleMutation,
  useCreateUserMutation,
  useDecideCorrectionMutation,
  useDisableUserMutation,
  useGetAuditQuery,
  useGetTenantUsageQuery,
  useGetCorrectionsQuery,
  useGetHrDashboardQuery,
  useGetMyAttendanceQuery,
  useGetMyCorrectionsQuery,
  useGetMyDashboardQuery,
  useGetReportsQuery,
  useGetReviewedAttendanceQuery,
  useGetSchedulesQuery,
  useGetUsersQuery,
  useReplaceRolesMutation,
  useRequestCorrectionMutation,
} = workspaceApi

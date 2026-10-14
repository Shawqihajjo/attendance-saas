import { createApi, fetchBaseQuery } from '@reduxjs/toolkit/query/react'

export const baseApi = createApi({
  reducerPath: 'api',
  baseQuery: fetchBaseQuery({
    baseUrl: '/api/v1',
    credentials: 'include',
  }),
  tagTypes: ['Session', 'Branding', 'Health', 'Profile', 'Tenants', 'PlatformAudit', 'Quotas', 'Employees', 'Departments', 'Teams', 'Terminals', 'DevicePolicy', 'TerminalAudit', 'Attendance', 'Corrections', 'Schedules', 'Audit', 'Users', 'Reports', 'Explorer'],
  endpoints: () => ({}),
})

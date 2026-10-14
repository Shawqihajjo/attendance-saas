import { baseApi } from '../../shared/api/baseApi'

export type HealthResponse = {
  data: {
    status: string
    service: string
    time: string
  }
}

export type ReadinessResponse = {
  data: {
    status: string
    checks: Record<string, { status: string; detail?: string }>
  }
}

export const diagnosticsApi = baseApi.injectEndpoints({
  endpoints: (builder) => ({
    getHealth: builder.query<HealthResponse, void>({
      query: () => '/health',
      providesTags: ['Health'],
    }),
    getReadiness: builder.query<ReadinessResponse, void>({
      query: () => '/health/ready',
      providesTags: ['Health'],
    }),
  }),
})

export const { useGetHealthQuery, useGetReadinessQuery } = diagnosticsApi

import { baseApi } from '../../shared/api/baseApi'

export type SessionData = {
  authenticated: boolean
  principal: string | null
  displayName: string | null
  kind: 'platform' | 'tenant' | null
  roles: string[]
  permissions: string[]
}

export type SessionResponse = {
  data: SessionData
  meta: Record<string, never>
}

export type LoginRequest = {
  email: string
  password: string
  platform: boolean
}

export const sessionApi = baseApi.injectEndpoints({
  endpoints: (builder) => ({
    getSession: builder.query<SessionResponse, void>({
      query: () => '/auth/session',
      providesTags: ['Session'],
    }),
    login: builder.mutation<SessionResponse, LoginRequest>({
      query: ({ email, password, platform }) => ({
        url: platform ? '/platform/login' : '/auth/login',
        method: 'POST',
        body: { email, password },
      }),
      invalidatesTags: ['Session', 'Profile', 'Tenants'],
    }),
    logout: builder.mutation<{ data: { ended: boolean } }, { platform: boolean }>({
      query: ({ platform }) => ({
        url: platform ? '/platform/logout' : '/auth/logout',
        method: 'POST',
      }),
      invalidatesTags: ['Session', 'Profile', 'Tenants'],
    }),
  }),
})

export const { useGetSessionQuery, useLoginMutation, useLogoutMutation } = sessionApi

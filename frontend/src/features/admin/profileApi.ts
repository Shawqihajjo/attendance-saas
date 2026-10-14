import { baseApi } from '../../shared/api/baseApi'

export type ProfileResponse = {
  data: {
    email: string
    displayName: string
    roles: string[]
    employeeId: string | null
  }
  meta: Record<string, never>
}

export const profileApi = baseApi.injectEndpoints({
  endpoints: (builder) => ({
    getProfile: builder.query<ProfileResponse, void>({
      query: () => '/me/profile',
      providesTags: ['Profile'],
    }),
  }),
})

export const { useGetProfileQuery } = profileApi

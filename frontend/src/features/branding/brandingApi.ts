import { baseApi } from '../../shared/api/baseApi'
import type { Branding } from '../../shared/theme/branding'

export type BrandingResponse = {
  data: Branding
  meta: Record<string, never>
}

export const brandingApi = baseApi.injectEndpoints({
  endpoints: (builder) => ({
    getBranding: builder.query<BrandingResponse, void>({
      query: () => '/public/branding',
      providesTags: ['Branding'],
    }),
    updateBranding: builder.mutation<BrandingResponse, {
      companyName: string
      logoUrl: string | null
      faviconUrl: string | null
      colors: {
        primary: string
        secondary: string
        accent: string
        background: string
        text: string
      }
    }>({
      query: (body) => ({ url: '/hr/branding', method: 'PATCH', body }),
      invalidatesTags: ['Branding'],
    }),
    updateTimezone: builder.mutation<{ data: { timezone: string } }, { timezone: string }>({
      query: (body) => ({ url: '/hr/settings/timezone', method: 'PATCH', body }),
      invalidatesTags: ['Branding'],
    }),
    updateLocales: builder.mutation<{ data: { locales: string[]; defaultLocale: string } }, { locales: string[]; defaultLocale: string }>({
      query: (body) => ({ url: '/hr/settings/locales', method: 'PUT', body }),
      invalidatesTags: ['Branding'],
    }),
    updateRegional: builder.mutation<BrandingResponse, { dateFormat: string; timeFormat: string; weekStartsOn: number }>({
      query: (body) => ({ url: '/hr/settings/regional', method: 'PATCH', body }),
      invalidatesTags: ['Branding'],
    }),
    updatePhotoPolicy: builder.mutation<{ data: { photoRequired: boolean } }, { photoRequired: boolean }>({
      query: (body) => ({ url: '/hr/settings/photo-policy', method: 'PATCH', body }),
      invalidatesTags: ['Branding'],
    }),
  }),
})

export const {
  useGetBrandingQuery,
  useUpdateBrandingMutation,
  useUpdateLocalesMutation,
  useUpdatePhotoPolicyMutation,
  useUpdateRegionalMutation,
  useUpdateTimezoneMutation,
} = brandingApi

import { createSlice } from '@reduxjs/toolkit'
import type { Branding } from '../../shared/theme/branding'
import { brandingApi } from './brandingApi'

export type BrandingState = {
  branding: Branding | null
}

const initialState: BrandingState = {
  branding: null,
}

const brandingSlice = createSlice({
  name: 'branding',
  initialState,
  reducers: {},
  extraReducers: (builder) => {
    builder.addMatcher(brandingApi.endpoints.getBranding.matchFulfilled, (state, action) => {
      state.branding = action.payload.data
    })
  },
})

export const brandingReducer = brandingSlice.reducer

import { configureStore } from '@reduxjs/toolkit'
import { setupListeners } from '@reduxjs/toolkit/query'
import { baseApi } from '../shared/api/baseApi'
import { brandingReducer } from '../features/branding/brandingSlice'
import { kioskReducer } from '../features/kiosk/kioskSlice'
import { explorerReducer } from '../features/platform/explorerSlice'
import { sessionReducer } from '../features/session/sessionSlice'

export const store = configureStore({
  reducer: {
    session: sessionReducer,
    branding: brandingReducer,
    kiosk: kioskReducer,
    explorer: explorerReducer,
    [baseApi.reducerPath]: baseApi.reducer,
  },
  middleware: (getDefaultMiddleware) => getDefaultMiddleware().concat(baseApi.middleware),
})

setupListeners(store.dispatch)

export type RootState = ReturnType<typeof store.getState>
export type AppDispatch = typeof store.dispatch

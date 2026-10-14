import { createSlice } from '@reduxjs/toolkit'
import { sessionApi } from './sessionApi'

export type SessionStatus = 'unknown' | 'anonymous' | 'authenticated'

export type SessionState = {
  status: SessionStatus
  roles: string[]
  permissions: string[]
  displayName: string | null
  kind: 'platform' | 'tenant' | null
}

const initialState: SessionState = {
  status: 'unknown',
  roles: [],
  permissions: [],
  displayName: null,
  kind: null,
}

const sessionSlice = createSlice({
  name: 'session',
  initialState,
  reducers: {},
  extraReducers: (builder) => {
    builder.addMatcher(sessionApi.endpoints.getSession.matchFulfilled, (state, action) => {
      applySession(state, action.payload.data)
    })
    builder.addMatcher(sessionApi.endpoints.login.matchFulfilled, (state, action) => {
      applySession(state, action.payload.data)
    })
    builder.addMatcher(sessionApi.endpoints.logout.matchFulfilled, (state) => {
      state.status = 'anonymous'
      state.roles = []
      state.permissions = []
      state.displayName = null
      state.kind = null
    })
    builder.addMatcher(sessionApi.endpoints.getSession.matchRejected, (state) => {
      state.status = 'unknown'
      state.roles = []
      state.permissions = []
      state.displayName = null
      state.kind = null
    })
  },
})

function applySession(state: SessionState, session: {
  authenticated: boolean
  roles: string[]
  permissions: string[]
  displayName: string | null
  kind: 'platform' | 'tenant' | null
}): void {
  state.status = session.authenticated ? 'authenticated' : 'anonymous'
  state.roles = session.roles
  state.permissions = session.permissions
  state.displayName = session.displayName
  state.kind = session.kind
}

export const sessionReducer = sessionSlice.reducer

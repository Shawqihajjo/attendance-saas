import { createSlice, type PayloadAction } from '@reduxjs/toolkit'
import { offeredActions, type AllowedAction, type RetryTarget, type TerminalStep } from './terminalMachine'

export type KioskEmployee = {
  displayName: string
  employeeNumber: string
}

export type KioskState = {
  step: TerminalStep
  attemptId: string | null
  employee: KioskEmployee | null
  terminalTimezone: string | null
  attendanceState: string | null
  allowedActions: AllowedAction[]
  selectedAction: string | null
  occurredAt: string | null
  messageKey: string | null
  retry: RetryTarget | null
}

const initialState: KioskState = {
  step: 'idle',
  attemptId: null,
  employee: null,
  terminalTimezone: null,
  attendanceState: null,
  allowedActions: [],
  selectedAction: null,
  occurredAt: null,
  messageKey: null,
  retry: null,
}

const kioskSlice = createSlice({
  name: 'kiosk',
  initialState,
  reducers: {
    resetTerminal: () => initialState,
    terminalStep: (state, action: PayloadAction<TerminalStep>) => {
      state.step = action.payload
      state.messageKey = null
      state.retry = null
    },
    employeeIdentified: (state, action: PayloadAction<{
      attemptId: string
      employee: KioskEmployee
      terminalTimezone: string
    }>) => {
      state.step = 'employee_found'
      state.attemptId = action.payload.attemptId
      state.employee = action.payload.employee
      state.terminalTimezone = action.payload.terminalTimezone
    },
    fingerprintAccepted: (state, action: PayloadAction<{ state: string, allowedActions: AllowedAction[] }>) => {
      const allowed = offeredActions(action.payload.allowedActions)
      state.attendanceState = action.payload.state
      state.allowedActions = allowed
      state.selectedAction = allowed.length === 1 ? allowed[0].action : null
    },
    actionChosen: (state, action: PayloadAction<string>) => {
      state.selectedAction = action.payload
    },
    attendanceRecorded: (state, action: PayloadAction<{ action: string, occurredAt: string, state: string }>) => {
      state.step = 'success'
      state.selectedAction = action.payload.action
      state.occurredAt = action.payload.occurredAt
      state.attendanceState = action.payload.state
      state.messageKey = null
      state.retry = null
    },
    terminalFailed: (state, action: PayloadAction<{ messageKey: string, retry: RetryTarget | null }>) => {
      state.step = 'error'
      state.messageKey = action.payload.messageKey
      state.retry = action.payload.retry
    },
    terminalTimedOut: (state) => {
      state.step = 'timeout'
      state.messageKey = null
      state.retry = null
    },
  },
})

export const {
  resetTerminal,
  terminalStep,
  employeeIdentified,
  fingerprintAccepted,
  actionChosen,
  attendanceRecorded,
  terminalFailed,
  terminalTimedOut,
} = kioskSlice.actions

export const kioskReducer = kioskSlice.reducer

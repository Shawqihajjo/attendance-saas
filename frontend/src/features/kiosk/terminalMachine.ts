export type TerminalStep =
  | 'idle'
  | 'identifying'
  | 'employee_found'
  | 'waiting_for_fingerprint'
  | 'verifying'
  | 'choosing_action'
  | 'capturing_photo'
  | 'processing_attendance'
  | 'success'
  | 'error'
  | 'timeout'

export type AllowedAction = {
  action: string
  photoRequired: boolean
}

export function applyScannerKey(code: string, key: string): { code: string, submit: boolean } {
  if (key === 'Enter') {
    return { code, submit: code.trim() !== '' }
  }
  if (key === 'Backspace') {
    return { code: code.slice(0, -1), submit: false }
  }
  if (/^[A-Za-z0-9-]$/.test(key) && code.length < 16) {
    return { code: `${code}${key.toUpperCase()}`, submit: false }
  }

  return { code, submit: false }
}

export const terminalTiming = {
  fingerprintMs: 20_000,
  cameraMs: 20_000,
  stepMs: 45_000,
  successMs: 6_000,
  errorMs: 12_000,
  timeoutMs: 4_000,
} as const

const serverActions = new Set(['clock_in', 'clock_out', 'lunch_start', 'lunch_end'])

const reasonKeys = new Set([
  'unknown',
  'not_recognized',
  'network',
  'backend_unavailable',
  'fingerprint_failed',
  'fingerprint_mismatch',
  'device_unavailable',
  'device_disconnected',
  'timeout',
  'capture_failed',
  'hardware_communication_failure',
  'matcher_unavailable',
  'fingerprint_required',
  'photo_required',
  'photo_invalid',
  'illegal_transition',
  'attempt_expired',
  'attempt_closed',
  'device_rejected',
  'device_inactive',
  'device_policy',
  'terminal_not_ready',
])

export type AttemptPhase = 'identify' | 'fingerprint' | 'photo' | 'commit'

export type RetryTarget = AttemptPhase

export type FailurePresentation = {
  messageKey: string
  retry: RetryTarget | null
}

export function offeredActions(actions: AllowedAction[]): AllowedAction[] {
  return actions.filter((action) => serverActions.has(action.action))
}

export function stepAfterVerification(actions: AllowedAction[]): TerminalStep {
  const offered = offeredActions(actions)
  if (offered.length === 0) {
    return 'error'
  }
  if (offered.length > 1) {
    return 'choosing_action'
  }

  return offered[0]?.photoRequired ? 'capturing_photo' : 'processing_attendance'
}

export function stepAfterAction(action: AllowedAction | undefined): TerminalStep {
  if (!action) {
    return 'error'
  }

  return action.photoRequired ? 'capturing_photo' : 'processing_attendance'
}

export function idleReturnMs(step: TerminalStep): number | null {
  if (step === 'idle') {
    return null
  }
  if (step === 'success') {
    return terminalTiming.successMs
  }
  if (step === 'error') {
    return terminalTiming.errorMs
  }
  if (step === 'timeout') {
    return terminalTiming.timeoutMs
  }

  return terminalTiming.stepMs
}

export function failurePresentation(phase: AttemptPhase, code: string | undefined): FailurePresentation {
  if (code === 'network') {
    return { messageKey: 'kiosk.reason.network', retry: phase }
  }
  if (code === 'backend_unavailable') {
    return { messageKey: 'kiosk.reason.backend_unavailable', retry: phase === 'identify' ? null : phase }
  }
  if (phase === 'identify' && (code === 'unknown_code' || code === 'employee_inactive' || code === 'employee_not_found')) {
    return { messageKey: 'kiosk.reason.not_recognized', retry: null }
  }
  if (code === 'attempt_expired' || code === 'attempt_closed' || code === 'illegal_transition' || code === 'idempotency_conflict') {
    return { messageKey: reasonKey(code), retry: null }
  }
  if (phase === 'fingerprint') {
    return { messageKey: reasonKey(code ?? 'fingerprint_failed'), retry: 'fingerprint' }
  }
  if (phase === 'photo') {
    return { messageKey: reasonKey(code ?? 'capture_failed'), retry: 'photo' }
  }

  return { messageKey: reasonKey(code), retry: phase === 'commit' ? 'commit' : null }
}

export function rememberIdempotencyKey(keys: Map<string, string>, attemptId: string, action: string, create: () => string): string {
  const id = `${attemptId}:${action}`
  const existing = keys.get(id)
  if (existing) {
    return existing
  }
  const created = create()
  keys.set(id, created)

  return created
}

function reasonKey(code: string | undefined): string {
  if (code && reasonKeys.has(code)) {
    return `kiosk.reason.${code}`
  }

  return 'kiosk.reason.unknown'
}

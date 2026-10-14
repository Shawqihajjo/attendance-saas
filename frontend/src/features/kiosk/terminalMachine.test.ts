import { applyScannerKey, failurePresentation, idleReturnMs, offeredActions, rememberIdempotencyKey, stepAfterAction, stepAfterVerification, terminalTiming } from './terminalMachine'

describe('terminal flow', () => {
  it('accepts scanner characters and submits on enter', () => {
    let code = ''
    for (const key of ['a', 'b', '1', '2', '-', 'Enter']) {
      const next = applyScannerKey(code, key)
      code = next.code
      if (key === 'Enter') {
        expect(next.submit).toBe(true)
      }
    }
    expect(code).toBe('AB12-')
    expect(applyScannerKey('AB', 'Backspace').code).toBe('A')
    expect(applyScannerKey('', 'Enter').submit).toBe(false)
  })

  it('follows the actions returned by the server', () => {
    expect(stepAfterVerification([{ action: 'clock_in', photoRequired: true }])).toBe('capturing_photo')
    expect(stepAfterVerification([{ action: 'clock_out', photoRequired: false }])).toBe('processing_attendance')
    expect(stepAfterVerification([
      { action: 'lunch_start', photoRequired: false },
      { action: 'clock_out', photoRequired: false },
    ])).toBe('choosing_action')
    expect(stepAfterVerification([])).toBe('error')
    expect(stepAfterAction({ action: 'clock_in', photoRequired: true })).toBe('capturing_photo')
    expect(stepAfterAction(undefined)).toBe('error')
    expect(stepAfterVerification([{ action: 'break', photoRequired: false }])).toBe('error')
  })

  it('offers only the actions the server returned', () => {
    const offered = offeredActions([
      { action: 'clock_out', photoRequired: false },
      { action: 'break', photoRequired: false },
    ])

    expect(offered.map((action) => action.action)).toEqual(['clock_out'])
    expect(offered.map((action) => action.action)).not.toContain('clock_in')
    expect(offered.map((action) => action.action)).not.toContain('lunch_start')
    expect(offered.map((action) => action.action)).not.toContain('lunch_end')
  })

  it('uses one message for a missing or inactive employee and does not record attendance', () => {
    const missing = failurePresentation('identify', 'unknown_code')
    const inactive = failurePresentation('identify', 'employee_inactive')

    expect(missing).toEqual(inactive)
    expect(missing.messageKey).toBe('kiosk.reason.not_recognized')
    expect(missing.retry).toBeNull()
    expect(failurePresentation('fingerprint', 'fingerprint_mismatch')).toEqual({
      messageKey: 'kiosk.reason.fingerprint_mismatch',
      retry: 'fingerprint',
    })
    expect(failurePresentation('photo', 'capture_failed').retry).toBe('photo')
    expect(failurePresentation('commit', 'network')).toEqual({
      messageKey: 'kiosk.reason.network',
      retry: 'commit',
    })
    expect(failurePresentation('commit', 'illegal_transition').retry).toBeNull()
  })

  it('keeps an offline or hardware failure from looking like recorded attendance', () => {
    expect(failurePresentation('commit', 'network')).toEqual({
      messageKey: 'kiosk.reason.network',
      retry: 'commit',
    })
    expect(failurePresentation('identify', 'backend_unavailable').retry).toBeNull()
    expect(failurePresentation('photo', 'capture_failed')).toEqual({
      messageKey: 'kiosk.reason.capture_failed',
      retry: 'photo',
    })
    expect(failurePresentation('identify', 'device_inactive').retry).toBeNull()
    expect(failurePresentation('identify', 'device_rejected').retry).toBeNull()

    const keys = new Map<string, string>()
    const first = rememberIdempotencyKey(keys, 'attempt', 'clock_in', () => 'key-1')
    const retried = rememberIdempotencyKey(keys, 'attempt', 'clock_in', () => 'key-2')
    expect(first).toBe(retried)
    expect(stepAfterVerification([{ action: 'clock_in', photoRequired: true }])).not.toBe('success')
  })

  it('reuses the idempotency key and returns to idle on a timer', () => {
    const keys = new Map<string, string>()
    const first = rememberIdempotencyKey(keys, 'attempt', 'clock_in', () => 'key-1')
    const second = rememberIdempotencyKey(keys, 'attempt', 'clock_in', () => 'key-2')

    expect(first).toBe('key-1')
    expect(second).toBe('key-1')
    expect(idleReturnMs('success')).toBe(terminalTiming.successMs)
    expect(idleReturnMs('error')).toBe(terminalTiming.errorMs)
    expect(idleReturnMs('waiting_for_fingerprint')).toBe(terminalTiming.stepMs)
    expect(idleReturnMs('idle')).toBeNull()
  })
})

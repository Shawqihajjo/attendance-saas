import { describeBiometric, MockCameraAdapter, MockFingerprintAdapter, resolveFingerprintAdapter, resolvePhotoAdapter, TerminalDeviceService, hardwareError, withCaptureDeadline, type CaptureResult, type HardwareBridge } from './hardware'
import { vi } from 'vitest'

describe('terminal hardware adapters', () => {
  it('uses an injected bridge and otherwise a development mock', async () => {
    const fingerprint = {
      vendorId: () => 'vendor',
      availability: async () => 'available' as const,
      health: async () => ({ status: 'healthy' as const, availability: 'available' as const, error: null }),
      enrollmentStatus: async () => 'unknown' as const,
      capture: async () => ({ status: 'captured' as const, value: { sample: 'from-bridge' } }),
    }
    const bridge: HardwareBridge = { fingerprint }
    const bridged = await new TerminalDeviceService(fingerprint, new MockCameraAdapter()).readFingerprint('attempt')
    expect(bridge.fingerprint).toBe(fingerprint)
    expect(bridged).toEqual({ status: 'captured', value: { sample: 'from-bridge' } })

    const mock = await resolveFingerprintAdapter(undefined, false).capture('attempt')
    expect(mock).toEqual({ status: 'captured', value: { sample: 'dev-verify' } })
    expect(resolveFingerprintAdapter(undefined, false).vendorId()).toBe('mock')
  })

  it('does not invent a fingerprint or camera in production', async () => {
    expect(await resolveFingerprintAdapter(undefined, true).capture('attempt')).toEqual({
      status: 'failed',
      error: hardwareError.deviceUnavailable,
    })
    expect(await resolvePhotoAdapter(undefined, true).capture('attempt')).toEqual({
      status: 'failed',
      error: hardwareError.deviceUnavailable,
    })
  })

  it('reports hardware faults without returning biometric material', async () => {
    const reader = new MockFingerprintAdapter({ fault: hardwareError.deviceDisconnected })
    const result = await new TerminalDeviceService(reader, new MockCameraAdapter({ fault: hardwareError.timeout })).readFingerprint('attempt')
    expect(result).toEqual({ status: 'failed', error: hardwareError.deviceDisconnected })
    expect(JSON.stringify(result)).not.toContain('dev-verify')

    const timedOut = await new MockFingerprintAdapter({ fault: hardwareError.timeout }).capture('attempt')
    expect(timedOut).toEqual({ status: 'failed', error: hardwareError.timeout })

    vi.useFakeTimers()
    const hanging = new Promise<CaptureResult<{ sample: string }>>(() => undefined)
    const deadline = withCaptureDeadline(hanging, 50)
    await vi.advanceTimersByTimeAsync(50)
    await expect(deadline).resolves.toEqual({ status: 'failed', error: hardwareError.timeout })
    expect(JSON.stringify(await deadline)).not.toContain('sample')
    vi.useRealTimers()
    expect(await new MockFingerprintAdapter().enrollmentStatus('employee-1')).toBe('unknown')
    expect(await new MockCameraAdapter({ fault: hardwareError.captureFailed }).capture('attempt')).toEqual({
      status: 'failed',
      error: hardwareError.captureFailed,
    })
    expect(JSON.stringify(describeBiometric('raw-fingerprint-sample'))).not.toContain('raw-fingerprint-sample')
  })
})

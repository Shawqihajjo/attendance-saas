export const hardwareError = {
  deviceUnavailable: 'device_unavailable',
  deviceDisconnected: 'device_disconnected',
  fingerprintMismatch: 'fingerprint_mismatch',
  timeout: 'timeout',
  captureFailed: 'capture_failed',
  hardwareCommunicationFailure: 'hardware_communication_failure',
} as const

export type HardwareErrorCode = (typeof hardwareError)[keyof typeof hardwareError]

export type DeviceAvailability = 'available' | 'unavailable' | 'disconnected'

export type DeviceHealth = {
  status: 'healthy' | 'degraded' | 'unhealthy'
  availability: DeviceAvailability
  error: HardwareErrorCode | null
}

export type EnrollmentStatus = 'enrolled' | 'not_enrolled' | 'unknown'

export type CaptureResult<T> =
  | { status: 'captured', value: T }
  | { status: 'failed', error: HardwareErrorCode }

export interface FingerprintDevice {
  vendorId(): string
  availability(): Promise<DeviceAvailability>
  health(): Promise<DeviceHealth>
  enrollmentStatus(employeeRef: string): Promise<EnrollmentStatus>
  capture(attemptId: string): Promise<CaptureResult<{ sample: string }>>
}

export interface CameraDevice {
  vendorId(): string
  availability(): Promise<DeviceAvailability>
  health(): Promise<DeviceHealth>
  capture(attemptId: string): Promise<CaptureResult<{ contentType: string, bytes: Uint8Array }>>
}

export type HardwareBridge = {
  fingerprint?: FingerprintDevice
  camera?: CameraDevice
}

declare global {
  interface Window {
    attendanceHardware?: HardwareBridge
  }
}

type AdapterOptions = {
  fault?: HardwareErrorCode
  delayMs?: number
}

const developmentJpeg = Uint8Array.from([
  0xff, 0xd8, 0xff, 0xe0, 0x00, 0x10, 0x4a, 0x46, 0x49, 0x46, 0x00, 0x01, 0xff, 0xd9,
])

function healthy(): DeviceHealth {
  return { status: 'healthy', availability: 'available', error: null }
}

function unhealthy(availability: DeviceAvailability, error: HardwareErrorCode): DeviceHealth {
  return { status: 'unhealthy', availability, error }
}

function availabilityFor(fault: HardwareErrorCode | undefined): DeviceAvailability {
  if (fault === hardwareError.deviceDisconnected) {
    return 'disconnected'
  }
  if (fault === hardwareError.deviceUnavailable) {
    return 'unavailable'
  }

  return 'available'
}

export function describeBiometric(sample: string): { byteLength: number } {
  return { byteLength: sample.length }
}

export class MockFingerprintAdapter implements FingerprintDevice {
  private readonly options: AdapterOptions

  constructor(options: AdapterOptions = {}) {
    this.options = options
  }

  vendorId(): string {
    return 'mock'
  }

  availability(): Promise<DeviceAvailability> {
    return Promise.resolve(availabilityFor(this.options.fault))
  }

  async health(): Promise<DeviceHealth> {
    const availability = await this.availability()
    if (availability === 'available' && !this.options.fault) {
      return healthy()
    }

    return unhealthy(availability, this.options.fault ?? hardwareError.deviceUnavailable)
  }

  enrollmentStatus(employeeRef: string): Promise<EnrollmentStatus> {
    return Promise.resolve(employeeRef === '' ? 'not_enrolled' : 'unknown')
  }

  capture(attemptId: string): Promise<CaptureResult<{ sample: string }>> {
    return new Promise((resolve) => {
      window.setTimeout(() => {
        if (attemptId === '' || this.options.fault) {
          resolve({ status: 'failed', error: attemptId === '' ? hardwareError.captureFailed : this.options.fault ?? hardwareError.captureFailed })
          return
        }
        resolve({ status: 'captured', value: { sample: 'dev-verify' } })
      }, this.options.delayMs ?? 0)
    })
  }
}

export class MockCameraAdapter implements CameraDevice {
  private readonly options: AdapterOptions

  constructor(options: AdapterOptions = {}) {
    this.options = options
  }

  vendorId(): string {
    return 'mock'
  }

  availability(): Promise<DeviceAvailability> {
    return Promise.resolve(availabilityFor(this.options.fault))
  }

  async health(): Promise<DeviceHealth> {
    const availability = await this.availability()
    if (availability === 'available' && !this.options.fault) {
      return healthy()
    }

    return unhealthy(availability, this.options.fault ?? hardwareError.deviceUnavailable)
  }

  capture(attemptId: string): Promise<CaptureResult<{ contentType: string, bytes: Uint8Array }>> {
    if (attemptId === '' || this.options.fault) {
      return Promise.resolve({ status: 'failed', error: attemptId === '' ? hardwareError.captureFailed : this.options.fault ?? hardwareError.captureFailed })
    }

    return Promise.resolve({
      status: 'captured',
      value: { contentType: 'image/jpeg', bytes: developmentJpeg },
    })
  }
}

const unavailableFingerprint: FingerprintDevice = {
  vendorId: () => 'none',
  availability: () => Promise.resolve('unavailable'),
  health: () => Promise.resolve(unhealthy('unavailable', hardwareError.deviceUnavailable)),
  enrollmentStatus: () => Promise.resolve('unknown'),
  capture: () => Promise.resolve({ status: 'failed', error: hardwareError.deviceUnavailable }),
}

const unavailableCamera: CameraDevice = {
  vendorId: () => 'none',
  availability: () => Promise.resolve('unavailable'),
  health: () => Promise.resolve(unhealthy('unavailable', hardwareError.deviceUnavailable)),
  capture: () => Promise.resolve({ status: 'failed', error: hardwareError.deviceUnavailable }),
}

export class TerminalDeviceService {
  readonly fingerprint: FingerprintDevice
  readonly camera: CameraDevice

  constructor(fingerprint: FingerprintDevice, camera: CameraDevice) {
    this.fingerprint = fingerprint
    this.camera = camera
  }

  static resolve(bridge: HardwareBridge | undefined, production: boolean): TerminalDeviceService {
    return new TerminalDeviceService(
      bridge?.fingerprint ?? (production ? unavailableFingerprint : new MockFingerprintAdapter({ delayMs: 600 })),
      bridge?.camera ?? (production ? unavailableCamera : new MockCameraAdapter()),
    )
  }

  async readFingerprint(attemptId: string, deadlineMs = 20_000): Promise<CaptureResult<{ sample: string }>> {
    const availability = await this.fingerprint.availability()
    if (availability === 'disconnected') {
      return { status: 'failed', error: hardwareError.deviceDisconnected }
    }
    if (availability !== 'available') {
      return { status: 'failed', error: hardwareError.deviceUnavailable }
    }

    return withCaptureDeadline(this.fingerprint.capture(attemptId), deadlineMs)
  }

  async takePhotograph(attemptId: string, deadlineMs = 20_000): Promise<CaptureResult<{ contentType: string, bytes: Uint8Array }>> {
    const availability = await this.camera.availability()
    if (availability === 'disconnected') {
      return { status: 'failed', error: hardwareError.deviceDisconnected }
    }
    if (availability !== 'available') {
      return { status: 'failed', error: hardwareError.deviceUnavailable }
    }

    return withCaptureDeadline(this.camera.capture(attemptId), deadlineMs)
  }
}

export function withCaptureDeadline<T>(work: Promise<CaptureResult<T>>, deadlineMs: number): Promise<CaptureResult<T>> {
  return new Promise((resolve) => {
    let settled = false
    const timer = window.setTimeout(() => {
      if (!settled) {
        settled = true
        resolve({ status: 'failed', error: hardwareError.timeout })
      }
    }, deadlineMs)
    work.then((result) => {
      if (!settled) {
        settled = true
        window.clearTimeout(timer)
        resolve(result)
      }
    }, () => {
      if (!settled) {
        settled = true
        window.clearTimeout(timer)
        resolve({ status: 'failed', error: hardwareError.hardwareCommunicationFailure })
      }
    })
  })
}

export function resolveFingerprintAdapter(bridge: HardwareBridge | undefined, production: boolean): FingerprintDevice {
  return TerminalDeviceService.resolve(bridge, production).fingerprint
}

export function resolvePhotoAdapter(bridge: HardwareBridge | undefined, production: boolean): CameraDevice {
  return TerminalDeviceService.resolve(bridge, production).camera
}

export function hardwareBridge(): HardwareBridge | undefined {
  return window.attendanceHardware
}

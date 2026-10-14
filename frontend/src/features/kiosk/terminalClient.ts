import { deviceFetch, type DeviceSigner } from '../../shared/api/deviceClient'
import { applicationVersion } from '../../shared/version'
import { signEd25519 } from './ed25519'
import { readTerminalCredential } from './terminalCredential'
import type { AllowedAction } from './terminalMachine'

export class TerminalNotEnrolledError extends Error {
  constructor() {
    super('This terminal is not enrolled.')
    this.name = 'TerminalNotEnrolledError'
  }
}

export class TerminalRequestError extends Error {
  readonly code: string

  constructor(code: string) {
    super(code)
    this.name = 'TerminalRequestError'
    this.code = code
  }
}

export type IdentifiedEmployee = {
  attemptId: string
  expiresAt: string
  terminalTimezone: string
  employee: {
    displayName: string
    employeeNumber: string
  }
}

export type VerificationResult = {
  verified: boolean
  state: string
  allowedActions: AllowedAction[]
}

export type AttendanceResult = {
  eventId: string
  action: string
  state: string
  occurredAt: string
  replayed: boolean
}

type ProblemBody = {
  code?: string
  detail?: string
}

async function signed(path: string, method: string, body?: string, bytes?: Uint8Array, headers?: Record<string, string>): Promise<Response> {
  const credential = readTerminalCredential()
  if (!credential) {
    throw new TerminalNotEnrolledError()
  }
  const signer: DeviceSigner = {
    sign: (payload) => signEd25519(credential.seed, payload),
  }

  try {
    return await deviceFetch({
      path,
      method,
      body,
      bytes,
      headers,
      deviceId: credential.deviceId,
      signer,
    })
  } catch (error) {
    if (error instanceof TerminalNotEnrolledError) {
      throw error
    }
    throw new TerminalRequestError('network')
  }
}

async function readData<T>(response: Response): Promise<T> {
  let payload: { data?: T } & ProblemBody
  try {
    payload = await response.json() as { data?: T } & ProblemBody
  } catch {
    throw new TerminalRequestError(response.status >= 500 ? 'backend_unavailable' : 'network')
  }
  if (!response.ok) {
    throw new TerminalRequestError(response.status >= 500 ? 'backend_unavailable' : (payload.code || 'unknown'))
  }
  if (!payload.data) {
    throw new TerminalRequestError('unknown')
  }

  return payload.data
}

export async function identifyEmployee(code: string): Promise<IdentifiedEmployee> {
  const response = await signed('/api/v1/terminal/attempts', 'POST', JSON.stringify({ code }), undefined, {
    'Content-Type': 'application/json',
  })

  return readData(response)
}

export async function verifyFingerprint(attemptId: string, vendor: string, sample: string): Promise<VerificationResult> {
  const response = await signed(`/api/v1/terminal/attempts/${attemptId}/fingerprint`, 'POST', JSON.stringify({ vendor, sample }), undefined, {
    'Content-Type': 'application/json',
  })

  return readData(response)
}

export async function uploadPhoto(attemptId: string, contentType: string, bytes: Uint8Array): Promise<void> {
  const response = await signed(`/api/v1/terminal/attempts/${attemptId}/photo`, 'POST', undefined, bytes, {
    'Content-Type': contentType,
  })
  await readData(response)
}

export async function recordHeartbeat(report: { os: string, fingerprintAdapter: string, cameraAdapter: string }): Promise<void> {
  const response = await signed('/api/v1/terminal/heartbeats', 'POST', JSON.stringify({
    ...report,
    appVersion: applicationVersion,
  }), undefined, {
    'Content-Type': 'application/json',
  })
  await readData(response)
}

export async function enrollTerminal(token: string, publicKey: string): Promise<{ deviceId: string, status: string }> {
  let response: Response
  try {
    response = await fetch('/api/v1/public/terminals/enroll', {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({ token, publicKey, appVersion: applicationVersion }),
    })
  } catch {
    throw new TerminalRequestError('network')
  }
  let payload: { data?: { deviceId: string, status: string }, code?: string }
  try {
    payload = await response.json() as { data?: { deviceId: string, status: string }, code?: string }
  } catch {
    throw new TerminalRequestError(response.status >= 500 ? 'backend_unavailable' : 'network')
  }
  if (!response.ok || !payload.data) {
    throw new TerminalRequestError(response.status >= 500 ? 'backend_unavailable' : (payload.code || 'unknown'))
  }

  return payload.data
}

export async function commitAttendance(attemptId: string, action: string, idempotencyKey: string): Promise<AttendanceResult> {
  const response = await signed(`/api/v1/terminal/attempts/${attemptId}/commit`, 'POST', JSON.stringify({ action }), undefined, {
    'Content-Type': 'application/json',
    'Idempotency-Key': idempotencyKey,
  })

  return readData(response)
}

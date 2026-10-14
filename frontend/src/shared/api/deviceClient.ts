import { applicationVersion } from '../version'

export class DeviceCredentialsMissingError extends Error {
  constructor() {
    super('No enrolled device credential is available.')
    this.name = 'DeviceCredentialsMissingError'
  }
}

export interface DeviceSigner {
  sign(payload: string): Promise<string>
}

export type DeviceRequest = {
  path: string
  method: string
  body?: string
  bytes?: Uint8Array
  deviceId?: string
  signer?: DeviceSigner
  headers?: Record<string, string>
}

export async function deviceFetch(request: DeviceRequest): Promise<Response> {
  if (!request.deviceId || !request.signer) {
    throw new DeviceCredentialsMissingError()
  }

  const bytes = request.bytes ?? new TextEncoder().encode(request.body ?? '')
  const timestamp = Math.floor(Date.now() / 1000).toString()
  const nonce = crypto.randomUUID()
  const payload = [
    'v1',
    timestamp,
    nonce,
    request.method.toUpperCase(),
    request.path,
    await sha256Hex(bytes),
  ].join('\n')
  const signature = await request.signer.sign(payload)
  const body = request.bytes ?? (request.body === undefined ? undefined : request.body)

  return fetch(request.path, {
    method: request.method,
    body,
    headers: {
      'X-Device-Id': request.deviceId,
      'X-Device-Timestamp': timestamp,
      'X-Device-Nonce': nonce,
      'X-Device-Signature': signature,
      'X-Device-App-Version': applicationVersion,
      ...request.headers,
    },
  })
}

export async function sha256Hex(value: Uint8Array | string): Promise<string> {
  const bytes = typeof value === 'string' ? new TextEncoder().encode(value) : value
  const digest = await crypto.subtle.digest('SHA-256', bytes)
  return [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, '0')).join('')
}

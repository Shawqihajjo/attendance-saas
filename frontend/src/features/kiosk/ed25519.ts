const pkcs8Prefix = Uint8Array.from([
  0x30, 0x2e, 0x02, 0x01, 0x00, 0x30, 0x05, 0x06, 0x03, 0x2b, 0x65, 0x70, 0x04, 0x22, 0x04, 0x20,
])

export async function createDeviceKey(): Promise<{ seed: string, publicKey: string }> {
  const seed = encodeBase64(crypto.getRandomValues(new Uint8Array(32)))

  return { seed, publicKey: await publicKeyFromSeed(seed) }
}

export async function publicKeyFromSeed(seedBase64: string): Promise<string> {
  const jwk = await crypto.subtle.exportKey('jwk', await importSeed(seedBase64, true))
  if (typeof jwk.x !== 'string' || jwk.x === '') {
    throw new Error('The terminal key is invalid.')
  }
  const padded = jwk.x.replace(/-/g, '+').replace(/_/g, '/')
  const binary = atob(padded.padEnd(padded.length + ((4 - (padded.length % 4)) % 4), '='))

  return encodeBase64(Uint8Array.from(binary, (character) => character.charCodeAt(0)))
}

export async function signEd25519(seedBase64: string, payload: string): Promise<string> {
  const key = await importSeed(seedBase64, false)
  const signature = new Uint8Array(await crypto.subtle.sign({ name: 'Ed25519' }, key, new TextEncoder().encode(payload)))

  return encodeBase64(signature)
}

async function importSeed(seedBase64: string, extractable: boolean): Promise<CryptoKey> {
  const seed = decodeBase64(seedBase64)
  if (seed.length !== 32) {
    throw new Error('The terminal key is invalid.')
  }
  const pkcs8 = new Uint8Array(pkcs8Prefix.length + seed.length)
  pkcs8.set(pkcs8Prefix)
  pkcs8.set(seed, pkcs8Prefix.length)

  return crypto.subtle.importKey('pkcs8', pkcs8, { name: 'Ed25519' }, extractable, ['sign'])
}

function decodeBase64(value: string): Uint8Array {
  const binary = atob(value)
  return Uint8Array.from(binary, (character) => character.charCodeAt(0))
}

function encodeBase64(value: Uint8Array): string {
  let binary = ''
  for (const byte of value) {
    binary += String.fromCharCode(byte)
  }

  return btoa(binary)
}

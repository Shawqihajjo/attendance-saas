export type TerminalCredential = {
  deviceId: string
  seed: string
}

const storageKey = 'terminal.credential'

export function writeTerminalCredential(credential: TerminalCredential): void {
  window.localStorage.setItem(storageKey, JSON.stringify(credential))
}

export function readTerminalCredential(): TerminalCredential | null {
  const raw = window.localStorage.getItem(storageKey)
  if (!raw) {
    return null
  }
  try {
    const parsed = JSON.parse(raw) as Partial<TerminalCredential>
    if (typeof parsed.deviceId !== 'string' || typeof parsed.seed !== 'string' || parsed.deviceId === '' || parsed.seed === '') {
      return null
    }

    return { deviceId: parsed.deviceId, seed: parsed.seed }
  } catch {
    return null
  }
}

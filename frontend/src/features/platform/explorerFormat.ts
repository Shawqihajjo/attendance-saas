const hiddenColumns = new Set([
  'password',
  'password_hash',
  'password_ciphertext',
  'token',
  'token_hash',
  'ciphertext',
  'config_ciphertext',
  'private_key',
  'public_key',
  'template_reference',
  'storage_key',
  'secret',
])

const instantPattern = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(?:\.\d+)?(?:Z|\+00:00)?$/

export type ExplorerCell = {
  text: string
  kind: 'null' | 'masked' | 'text'
}

export function isHiddenExplorerColumn(name: string): boolean {
  return hiddenColumns.has(name.toLowerCase())
}

export function presentExplorerValue(column: string, value: unknown, formatDate: (iso: string) => string): ExplorerCell {
  if (isHiddenExplorerColumn(column)) {
    return { text: '', kind: 'masked' }
  }
  if (value === null || value === undefined) {
    return { text: '', kind: 'null' }
  }
  if (typeof value === 'boolean') {
    return { text: value ? 'true' : 'false', kind: 'text' }
  }
  if (typeof value === 'number' && Number.isFinite(value)) {
    return { text: String(value), kind: 'text' }
  }
  if (typeof value !== 'string' || value.includes('\u0000')) {
    return { text: '', kind: 'masked' }
  }
  const instant = instantPattern.exec(value)
  if (instant) {
    const formatted = formatDate(`${instant[1]}T${instant[2]}Z`)
    if (formatted !== '') {
      return { text: formatted, kind: 'text' }
    }
  }
  const compact = value.replace(/\s+/g, ' ').trim()
  if (compact.length > 180) {
    return { text: `${compact.slice(0, 180)}…`, kind: 'text' }
  }

  return { text: compact, kind: 'text' }
}

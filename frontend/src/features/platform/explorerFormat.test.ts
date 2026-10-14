import { presentExplorerValue } from './explorerFormat'

describe('explorer cell formatting', () => {
  const formatDate = (iso: string) => (iso.startsWith('2026-10-12') ? '12 Oct 2026, 08:00' : '')

  it('formats nulls and dates and shortens large text', () => {
    expect(presentExplorerValue('display_name', null, formatDate)).toEqual({ text: '', kind: 'null' })
    expect(presentExplorerValue('created_at', '2026-10-12 08:00:00', formatDate).text).toBe('12 Oct 2026, 08:00')
    expect(presentExplorerValue('note', 'x'.repeat(200), formatDate).text.endsWith('…')).toBe(true)
    expect(presentExplorerValue('note', 'x'.repeat(200), formatDate).text.includes('x'.repeat(200))).toBe(false)
  })

  it('does not render sensitive or binary values', () => {
    expect(presentExplorerValue('password_hash', 'secret-hash', formatDate).kind).toBe('masked')
    expect(presentExplorerValue('template_reference', 'template', formatDate).text).toBe('')
    expect(presentExplorerValue('note', 'safe\u0000bytes', formatDate).kind).toBe('masked')
  })
})

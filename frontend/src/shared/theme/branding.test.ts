import { applyTenantTheme } from './branding'

const tenantA = {
  primary: '#111111',
  secondary: '#222222',
  accent: '#AA1100',
  surface: '#FFFFFF',
  background: '#FFF8F4',
  text: '#1A0A06',
}

const tenantB = {
  primary: '#F4F7FB',
  secondary: '#16356E',
  accent: 'blue',
  surface: '#102033',
  background: '#0B1220',
  text: '#F7F9FC',
}

describe('applyTenantTheme', () => {
  it('applies tenant A and tenant B colors as brand tokens', () => {
    applyTenantTheme(tenantA, 'light')

    expect(document.documentElement.style.getPropertyValue('--brand-primary')).toBe('#111111')
    expect(document.documentElement.style.getPropertyValue('--brand-surface')).toBe('#FFF8F4')
    expect(document.documentElement.style.getPropertyValue('--brand-on-primary')).toBe('#F7F9FC')
    expect(document.documentElement.dataset.appearance).toBe('light')

    applyTenantTheme(tenantB, 'dark')

    expect(document.documentElement.style.getPropertyValue('--brand-primary')).toBe('#F4F7FB')
    expect(document.documentElement.style.getPropertyValue('--brand-surface')).toBe('#0B1220')
    expect(document.documentElement.style.getPropertyValue('--brand-accent')).toBe('')
    expect(document.documentElement.style.getPropertyValue('--brand-on-primary')).toBe('#122033')
    expect(document.documentElement.style.getPropertyValue('--radius-card')).toBe('12px')
    expect(document.documentElement.style.getPropertyValue('--border-width')).toBe('1px')
    expect(document.documentElement.style.getPropertyValue('--color-primary')).toBe('')
    expect(document.documentElement.dataset.appearance).toBe('dark')
  })
})

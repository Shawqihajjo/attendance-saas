import { correctionRequest, optionalEmail, passwordText, reasonText, requiredText } from './validate'

describe('form validation', () => {
  it('accepts text, email, reason, and password lengths that match the account rules', () => {
    expect(requiredText('Ada')).toBeNull()
    expect(requiredText(' ')).toBe('workspace.invalidText')
    expect(requiredText('a'.repeat(201))).toBe('workspace.invalidText')
    expect(optionalEmail('')).toBeNull()
    expect(optionalEmail('ada@example.test')).toBeNull()
    expect(optionalEmail('ada')).toBe('workspace.invalidEmail')
    expect(reasonText('Missed clock-out')).toBeNull()
    expect(reasonText('')).toBe('workspace.invalidReason')
    expect(passwordText('correct-horse')).toBeNull()
    expect(passwordText('short')).toBe('workspace.invalidPassword')
  })

  it('requires a date, time, action, and reason for a correction', () => {
    const valid = { localDate: '2026-10-07', localTime: '17:00', action: 'clock_out', reason: 'Missed clock-out', comment: '', supportingInformation: '' }
    expect(correctionRequest(valid)).toBeNull()
    expect(correctionRequest({ ...valid, localDate: '' })).toBe('workspace.invalidDate')
    expect(correctionRequest({ ...valid, localTime: '5pm' })).toBe('workspace.invalidTime')
    expect(correctionRequest({ ...valid, action: '' })).toBe('workspace.invalidAction')
    expect(correctionRequest({ ...valid, reason: '' })).toBe('workspace.invalidReason')
    expect(correctionRequest({ ...valid, comment: 'a'.repeat(501) })).toBe('workspace.invalidComment')
    expect(correctionRequest({ ...valid, supportingInformation: 'a'.repeat(1001) })).toBe('workspace.invalidSupporting')
  })
})

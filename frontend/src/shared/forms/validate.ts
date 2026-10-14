const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

export function requiredText(value: string, max = 200): string | null {
  const trimmed = value.trim()
  if (trimmed === '' || trimmed.length > max) {
    return 'workspace.invalidText'
  }

  return null
}

export function optionalEmail(value: string): string | null {
  const trimmed = value.trim()
  if (trimmed === '') {
    return null
  }
  if (!emailPattern.test(trimmed) || trimmed.length > 200) {
    return 'workspace.invalidEmail'
  }

  return null
}

export function reasonText(value: string): string | null {
  const trimmed = value.trim()
  if (trimmed === '' || trimmed.length > 500) {
    return 'workspace.invalidReason'
  }

  return null
}

const correctionActions = ['clock_in', 'clock_out', 'lunch_start', 'lunch_end']

export function correctionRequest(input: { localDate: string; localTime: string; action: string; reason: string; comment: string; supportingInformation: string }): string | null {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(input.localDate)) {
    return 'workspace.invalidDate'
  }
  if (!/^\d{2}:\d{2}$/.test(input.localTime)) {
    return 'workspace.invalidTime'
  }
  if (!correctionActions.includes(input.action)) {
    return 'workspace.invalidAction'
  }
  const reason = reasonText(input.reason)
  if (reason) {
    return reason
  }
  if (input.comment.trim().length > 500) {
    return 'workspace.invalidComment'
  }
  if (input.supportingInformation.trim().length > 1000) {
    return 'workspace.invalidSupporting'
  }

  return null
}

export function passwordText(value: string): string | null {
  if (value.length < 12 || value.length > 200) {
    return 'workspace.invalidPassword'
  }

  return null
}

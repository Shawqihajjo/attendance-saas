export type ProblemDetails = {
  type: string
  title: string
  status: number
  detail: string
  code: string
  requestId?: string | null
  resource?: string
  current?: number
  limit?: number
  errors?: Array<{ field: string; message: string }>
}

export function isProblemDetails(value: unknown): value is ProblemDetails {
  if (typeof value !== 'object' || value === null) {
    return false
  }
  const record = value as Record<string, unknown>
  return typeof record.code === 'string' && typeof record.title === 'string' && typeof record.status === 'number'
}

export function problemFromQueryError(error: unknown): ProblemDetails | null {
  if (typeof error !== 'object' || error === null || !('data' in error)) {
    return null
  }
  const data = (error as { data?: unknown }).data
  return isProblemDetails(data) ? data : null
}

export function errorMessageKey(error: unknown): string {
  if (typeof error === 'object' && error !== null && 'status' in error) {
    const status = (error as { status?: unknown }).status
    if (status === 'FETCH_ERROR') {
      return 'errors.network'
    }
  }
  const problem = problemFromQueryError(error)
  if (problem?.code === 'tenant_quota_exceeded' && (problem.resource === 'users' || problem.resource === 'employees' || problem.resource === 'devices')) {
    return `errors.quota.${problem.resource}`
  }
  if (problem && ['unauthenticated', 'invalid_credentials', 'session_expired', 'forbidden', 'tenant_not_found', 'not_found', 'validation_failed', 'server_error', 'explorer_unavailable', 'query_rejected', 'query_timeout'].includes(problem.code)) {
    return `errors.${problem.code}`
  }
  return 'errors.unknown'
}

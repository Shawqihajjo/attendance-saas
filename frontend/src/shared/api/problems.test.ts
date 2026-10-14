import { errorMessageKey, problemFromQueryError } from './problems'

describe('problem helpers', () => {
  it('reads a problem payload from a query error', () => {
    const problem = problemFromQueryError({
      status: 401,
      data: {
        type: '/problems/unauthenticated',
        title: 'Authentication required',
        status: 401,
        detail: 'Sign in is required.',
        code: 'unauthenticated',
      },
    })

    expect(problem?.code).toBe('unauthenticated')
    expect(errorMessageKey({ status: 401, data: problem })).toBe('errors.unauthenticated')
  })

  it('maps a tenant quota problem to the blocked resource', () => {
    expect(errorMessageKey({
      status: 409,
      data: {
        type: '/problems/tenant_quota_exceeded',
        title: 'Quota exceeded',
        status: 409,
        detail: 'The employee limit has been reached.',
        code: 'tenant_quota_exceeded',
        resource: 'employees',
        current: 250,
        limit: 250,
      },
    })).toBe('errors.quota.employees')
  })

  it('maps network failures separately from problem payloads', () => {
    expect(errorMessageKey({ status: 'FETCH_ERROR', error: 'TypeError' })).toBe('errors.network')
  })
})

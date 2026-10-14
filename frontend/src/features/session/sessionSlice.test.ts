import { sessionApi } from './sessionApi'
import { sessionReducer } from './sessionSlice'

describe('sessionReducer', () => {
  it('starts unknown and becomes anonymous after the server session loads', () => {
    expect(sessionReducer(undefined, { type: 'unknown' }).status).toBe('unknown')

    const next = sessionReducer(undefined, {
      type: 'api/executeQuery/fulfilled',
      payload: {
        data: {
          authenticated: false,
          principal: null,
          displayName: null,
          kind: null,
          roles: [],
          permissions: [],
        },
        meta: {},
      },
      meta: {
        arg: {
          endpointName: sessionApi.endpoints.getSession.name,
          originalArgs: undefined,
          type: 'query',
        },
        requestId: 'test',
        requestStatus: 'fulfilled',
      },
    })

    expect(next.status).toBe('anonymous')
    expect(next.roles).toEqual([])
    expect(next.permissions).toEqual([])
    expect(next.kind).toBeNull()
  })

  it('stores the server permissions after sign-in and clears them on sign-out', () => {
    const signedIn = sessionReducer(undefined, {
      type: 'api/executeMutation/fulfilled',
      payload: {
        data: {
          authenticated: true,
          principal: null,
          displayName: 'Mia',
          kind: 'tenant',
          roles: ['manager'],
          permissions: ['employees.view_assigned', 'reports.view_team'],
        },
        meta: {},
      },
      meta: {
        arg: {
          endpointName: sessionApi.endpoints.login.name,
          originalArgs: undefined,
          type: 'mutation',
        },
        requestId: 'test',
        requestStatus: 'fulfilled',
      },
    })

    expect(signedIn.status).toBe('authenticated')
    expect(signedIn.roles).toEqual(['manager'])
    expect(signedIn.permissions).toEqual(['employees.view_assigned', 'reports.view_team'])
    expect(signedIn.displayName).toBe('Mia')
    expect('token' in signedIn).toBe(false)

    const signedOut = sessionReducer(signedIn, {
      type: 'api/executeMutation/fulfilled',
      payload: { data: {}, meta: {} },
      meta: {
        arg: {
          endpointName: sessionApi.endpoints.logout.name,
          originalArgs: undefined,
          type: 'mutation',
        },
        requestId: 'test',
        requestStatus: 'fulfilled',
      },
    })

    expect(signedOut.status).toBe('anonymous')
    expect(signedOut.permissions).toEqual([])
    expect(signedOut.roles).toEqual([])
  })
})

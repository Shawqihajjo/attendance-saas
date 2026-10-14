import { explorerReducer, initialExplorerState, selectTable, selectTarget, setQueryText } from './explorerSlice'

describe('explorer selection', () => {
  it('clears the previous tenant table, search, and query when the database changes', () => {
    const selected = explorerReducer(initialExplorerState, selectTarget('north'))
    const withTable = explorerReducer(selected, selectTable('employees'))
    const withQuery = explorerReducer(withTable, setQueryText('SELECT display_name FROM employees'))
    const switched = explorerReducer(withQuery, selectTarget('south'))

    expect(switched.target).toBe('south')
    expect(switched.table).toBeNull()
    expect(switched.q).toBe('')
    expect(switched.queryText).toBe('')
    expect(switched.offset).toBe(0)
    expect(switched.generation).toBe(selected.generation + 1)
  })
})

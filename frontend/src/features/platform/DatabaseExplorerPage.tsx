import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useAppDispatch, useAppSelector } from '../../app/hooks'
import { errorMessageKey } from '../../shared/api/problems'
import { useTenantFormat } from '../../shared/theme/useTenantFormat'
import { Alert } from '../../shared/ui/Alert'
import { Button } from '../../shared/ui/Button'
import { Page } from '../../shared/ui/Page'
import { QueryState } from '../../shared/ui/QueryState'
import {
  useGetExplorerDatabasesQuery,
  useGetExplorerRowsQuery,
  useGetExplorerTableQuery,
  useGetExplorerTablesQuery,
  useRunExplorerQueryMutation,
  type ExplorerColumn,
  type ExplorerRows,
} from './explorerApi'
import { presentExplorerValue } from './explorerFormat'
import {
  addFilter,
  applySearch,
  clearQuery,
  clearSearch,
  removeFilter,
  selectTable,
  selectTarget,
  setDraftQ,
  setOffset,
  setQueryHeight,
  setQueryText,
  setTableSearch,
  sortBy,
  toggleColumn,
  toggleQuery,
  toggleSchema,
  toggleStructure,
  toggleTree,
} from './explorerSlice'

const pageSize = 50

export function DatabaseExplorerPage() {
  const { t } = useTranslation()
  const dispatch = useAppDispatch()
  const explorer = useAppSelector((state) => state.explorer)
  const databases = useGetExplorerDatabasesQuery()
  const tables = useGetExplorerTablesQuery(explorer.target ?? '', { skip: explorer.target === null })
  const detail = useGetExplorerTableQuery(
    { target: explorer.target ?? '', table: explorer.table ?? '' },
    { skip: explorer.target === null || explorer.table === null },
  )
  const rows = useGetExplorerRowsQuery(
    {
      target: explorer.target ?? '',
      table: explorer.table ?? '',
      limit: pageSize,
      offset: explorer.offset,
      sort: explorer.sort ?? undefined,
      direction: explorer.direction,
      q: explorer.q === '' ? undefined : explorer.q,
      columns: explorer.columns === null ? undefined : explorer.columns.join(','),
      filters: explorer.filters,
    },
    { skip: explorer.target === null || explorer.table === null },
  )
  const [runQuery, queryState] = useRunExplorerQueryMutation()
  const [filterColumn, setFilterColumn] = useState('')
  const [filterValue, setFilterValue] = useState('')
  const [, setQueryVersion] = useState(0)
  const [phase, setPhase] = useState<'idle' | 'running' | 'success' | 'error' | 'cancelled'>('idle')
  const runId = useRef(0)
  const busy = useRef(false)
  const pending = useRef<{ abort?: () => void } | null>(null)
  const selected = databases.data?.data.find((item) => item.target === explorer.target)
  const visibleTables = (tables.data?.data ?? []).filter((table) => table.name.toLowerCase().includes(explorer.tableSearch.trim().toLowerCase()))
  const columnNames = detail.data?.data.columns.map((column) => column.name) ?? []
  const queryMatches = queryState.originalArgs?.ticket === explorer.generation
  const queryResult = queryMatches ? queryState.data : undefined
  const queryError = queryMatches ? queryState.error : undefined
  const seenGeneration = useRef(explorer.generation)

  useEffect(() => {
    if (seenGeneration.current === explorer.generation) {
      return
    }
    seenGeneration.current = explorer.generation
    runId.current += 1
    pending.current?.abort?.()
    pending.current = null
    busy.current = false
    setPhase('idle')
    queryState.reset()
  }, [explorer.generation, queryState])

  function chooseTarget(target: string) {
    dispatch(selectTarget(target))
    setFilterColumn('')
    setFilterValue('')
  }

  async function executeQuery() {
    if (busy.current || explorer.target === null || explorer.queryText.trim() === '') {
      return
    }
    const id = runId.current + 1
    runId.current = id
    busy.current = true
    setPhase('running')
    const request = runQuery({
      target: explorer.target,
      sql: explorer.queryText,
      ticket: explorer.generation,
    })
    pending.current = request
    try {
      await request.unwrap()
      if (runId.current !== id) {
        return
      }
      setPhase('success')
    } catch {
      if (runId.current !== id) {
        return
      }
      setPhase('error')
    } finally {
      if (pending.current === request) {
        pending.current = null
      }
      if (runId.current === id) {
        busy.current = false
      }
      setQueryVersion((version) => version + 1)
    }
  }

  function cancelQuery() {
    runId.current += 1
    pending.current?.abort?.()
    pending.current = null
    busy.current = false
    setPhase('cancelled')
  }

  return (
    <Page title={t('explorer.title')} intro={t('explorer.intro')}>
      <div className={explorer.treeOpen ? 'explorer tree-open' : 'explorer'}>
        <div className="explorer-toolbar">
          <Button type="button" variant="secondary" onClick={() => dispatch(toggleTree())}>
            {explorer.treeOpen ? t('explorer.hideDatabases') : t('explorer.databases')}
          </Button>
        </div>
        <aside className="explorer-tree panel" aria-label={t('explorer.databases')}>
          <QueryState isLoading={databases.isLoading} error={databases.error} empty={false} emptyLabel="">
            <p className="nav-label">{t('explorer.platform')}</p>
            {(databases.data?.data ?? []).filter((item) => item.kind === 'platform').map((item) => (
              <button key={item.target} type="button" className={item.target === explorer.target ? 'explorer-node active' : 'explorer-node'} aria-pressed={item.target === explorer.target} onClick={() => chooseTarget(item.target)}>
                {item.label}
              </button>
            ))}
            <p className="nav-label">{t('explorer.tenants')}</p>
            {(databases.data?.data ?? []).filter((item) => item.kind === 'tenant').map((item) => (
              <button key={item.target} type="button" className={item.target === explorer.target ? 'explorer-node active' : 'explorer-node'} aria-pressed={item.target === explorer.target} aria-label={item.status ? `${item.label} (${item.status})` : item.label} onClick={() => chooseTarget(item.target)}>
                <span>{item.label}</span>
                {item.status ? <small>{item.status}</small> : null}
              </button>
            ))}
            {explorer.target === null ? <p className="muted">{t('explorer.selectDatabase')}</p> : null}
            {explorer.target !== null ? (
              <QueryState isLoading={tables.isLoading} error={tables.error} empty={false} emptyLabel="">
                <label className="field">
                  <span>{t('explorer.searchTables')}</span>
                  <input value={explorer.tableSearch} onChange={(event) => dispatch(setTableSearch(event.target.value))} />
                </label>
                {visibleTables.length === 0 ? <p className="muted">{t('explorer.noTables')}</p> : null}
                <ul className="explorer-tables">
                  {visibleTables.map((table) => (
                    <li key={table.name}>
                      <button type="button" className={table.name === explorer.table ? 'explorer-node active' : 'explorer-node'} aria-pressed={table.name === explorer.table} onClick={() => dispatch(selectTable(table.name))}>
                        <span>{table.name}</span>
                        <small>{table.kind === 'view' ? t('explorer.view') : t('explorer.table')}</small>
                      </button>
                      {table.name === explorer.table && explorer.schemaOpen && detail.data ? (
                        <ul className="explorer-columns">
                          {detail.data.data.columns.map((column) => (
                            <li key={column.name}>{column.name}<small>{column.type}</small></li>
                          ))}
                        </ul>
                      ) : null}
                      {table.name === explorer.table ? (
                        <button type="button" className="explorer-inline" onClick={() => dispatch(toggleSchema())}>
                          {explorer.schemaOpen ? t('explorer.hideStructure') : t('explorer.structure')}
                        </button>
                      ) : null}
                    </li>
                  ))}
                </ul>
              </QueryState>
            ) : null}
          </QueryState>
        </aside>
        <section className="explorer-main" aria-label={t('explorer.data')}>
          {explorer.target === null || explorer.table === null ? <p className="muted">{t('explorer.selectTable')}</p> : null}
          {explorer.table !== null && detail.data ? (
            <div className="panel">
              <header className="page-header">
                <div>
                  <h2>{selected?.label ?? explorer.target} / {detail.data.data.name}</h2>
                  <p className="muted">
                    {detail.data.data.kind === 'view' ? t('explorer.view') : t('explorer.table')}
                    {' · '}
                    {detail.data.data.rowEstimate === null ? t('explorer.rowEstimateUnknown') : t('explorer.rowEstimate', { count: detail.data.data.rowEstimate })}
                  </p>
                </div>
                <Button type="button" variant="secondary" onClick={() => dispatch(toggleStructure())}>
                  {explorer.structureOpen ? t('explorer.hideStructure') : t('explorer.structure')}
                </Button>
              </header>
              {explorer.structureOpen ? <Structure columns={detail.data.data.columns} indexes={detail.data.data.indexes} foreignKeys={detail.data.data.foreignKeys} /> : null}
            </div>
          ) : null}
          {explorer.table !== null ? (
            <div className="panel">
              <form className="explorer-filters" onSubmit={(event) => { event.preventDefault(); dispatch(applySearch()) }}>
                <label className="field">
                  <span>{t('explorer.searchRows')}</span>
                  <input value={explorer.draftQ} onChange={(event) => dispatch(setDraftQ(event.target.value))} />
                </label>
                <Button type="submit">{t('explorer.search')}</Button>
                <Button type="button" variant="secondary" onClick={() => dispatch(clearSearch())}>{t('explorer.clearSearch')}</Button>
              </form>
              {columnNames.length > 0 ? (
                <form className="explorer-filters" onSubmit={(event) => {
                  event.preventDefault()
                  dispatch(addFilter({ column: filterColumn, value: filterValue }))
                  setFilterValue('')
                }}>
                  <label className="field">
                    <span>{t('explorer.filterColumn')}</span>
                    <select value={filterColumn} onChange={(event) => setFilterColumn(event.target.value)}>
                      <option value="">{t('explorer.filterColumn')}</option>
                      {columnNames.map((name) => <option key={name} value={name}>{name}</option>)}
                    </select>
                  </label>
                  <label className="field">
                    <span>{t('explorer.filterValue')}</span>
                    <input value={filterValue} onChange={(event) => setFilterValue(event.target.value)} />
                  </label>
                  <Button type="submit">{t('explorer.addFilter')}</Button>
                </form>
              ) : null}
              {explorer.filters.length > 0 ? (
                <ul className="explorer-chips">
                  {explorer.filters.map((filter) => (
                    <li key={filter.column}>
                      <button type="button" onClick={() => dispatch(removeFilter(filter.column))}>{filter.column} = {filter.value} · {t('explorer.removeFilter', { column: filter.column })}</button>
                    </li>
                  ))}
                </ul>
              ) : null}
              {columnNames.length > 0 ? (
                <fieldset className="field">
                  <legend>{t('explorer.visibleColumns')}</legend>
                  <div className="explorer-checks">
                    {columnNames.map((name) => {
                      const checked = explorer.columns === null || explorer.columns.includes(name)
                      return (
                        <label key={name}>
                          <input type="checkbox" checked={checked} onChange={() => dispatch(toggleColumn({ name, all: columnNames }))} />
                          {name}
                        </label>
                      )
                    })}
                  </div>
                </fieldset>
              ) : null}
              <QueryState
                isLoading={rows.isLoading || rows.isFetching}
                error={rows.error}
                empty={!rows.data || rows.data.data.rows.length === 0}
                emptyLabel={t('explorer.emptyRows')}
              >
                {rows.data ? (
                  <>
                    <ResultGrid
                      columns={rows.data.data.columns}
                      rows={rows.data.data.rows}
                      sort={explorer.sort}
                      direction={explorer.direction}
                      onSort={(column) => dispatch(sortBy(column))}
                    />
                    <div className="toolbar">
                      <Button type="button" variant="secondary" disabled={explorer.offset === 0} onClick={() => dispatch(setOffset(explorer.offset - pageSize))}>{t('explorer.previous')}</Button>
                      <span>{t('explorer.page', { returned: rows.data.meta.page?.returned ?? rows.data.data.rows.length, offset: explorer.offset })}</span>
                      <Button type="button" variant="secondary" disabled={rows.data.meta.page?.hasMore !== true} onClick={() => dispatch(setOffset(explorer.offset + pageSize))}>{t('explorer.next')}</Button>
                    </div>
                  </>
                ) : null}
              </QueryState>
            </div>
          ) : null}
        </section>
        <section className="explorer-query panel" aria-label={t('explorer.query')} style={explorer.queryOpen ? { minHeight: explorer.queryHeight } : undefined}>
          {explorer.queryOpen ? (
            <div
              className="explorer-resize"
              role="separator"
              aria-orientation="horizontal"
              aria-label={t('explorer.resize')}
              onPointerDown={(event) => {
                const startY = event.clientY
                const start = explorer.queryHeight
                const move = (pointer: PointerEvent) => {
                  dispatch(setQueryHeight(Math.min(480, Math.max(160, start - (pointer.clientY - startY)))))
                }
                const stop = () => {
                  window.removeEventListener('pointermove', move)
                  window.removeEventListener('pointerup', stop)
                }
                window.addEventListener('pointermove', move)
                window.addEventListener('pointerup', stop)
              }}
            />
          ) : null}
          <header className="page-header">
            <div>
              <h2>{t('explorer.query')}</h2>
              <p className="muted">{explorer.target === null ? t('explorer.selectDatabase') : t('explorer.queryTarget', { name: selected?.label ?? explorer.target })}</p>
            </div>
            <Button type="button" variant="secondary" onClick={() => dispatch(toggleQuery())}>
              {explorer.queryOpen ? t('explorer.hideQuery') : t('explorer.showQuery')}
            </Button>
          </header>
          {explorer.queryOpen ? (
            <>
              <p className="muted">{t('explorer.readOnly')}</p>
              <label className="field">
                <span className="sr-only">{t('explorer.query')}</span>
                <textarea className="explorer-editor" value={explorer.queryText} placeholder={t('explorer.queryPlaceholder')} spellCheck={false} onChange={(event) => dispatch(setQueryText(event.target.value))} />
              </label>
              <div className="toolbar">
                <Button type="button" disabled={explorer.target === null || explorer.queryText.trim() === '' || phase === 'running'} onClick={() => { void executeQuery() }}>
                  {phase === 'running' ? t('explorer.running') : t('explorer.run')}
                </Button>
                {phase === 'running' ? (
                  <Button type="button" variant="secondary" onClick={cancelQuery}>{t('explorer.cancel')}</Button>
                ) : null}
                <Button type="button" variant="secondary" onClick={() => { runId.current += 1; pending.current?.abort?.(); pending.current = null; busy.current = false; setPhase('idle'); dispatch(clearQuery()); queryState.reset() }}>{t('explorer.clear')}</Button>
              </div>
              <p role="status">
                {phase === 'running' ? t('explorer.running') : phase === 'cancelled' ? t('explorer.cancelled') : phase === 'error' ? t('explorer.failure') : phase === 'success' ? t('explorer.success') : t('explorer.idle')}
                {phase === 'success' && queryResult?.meta.durationMs !== undefined ? ` · ${t('explorer.duration', { ms: queryResult.meta.durationMs })}` : ''}
                {phase === 'success' && queryResult?.meta.page ? ` · ${t('explorer.returned', { count: queryResult.meta.page.returned })}` : ''}
              </p>
              {phase === 'error' && queryError ? <Alert>{t(errorMessageKey(queryError))}</Alert> : null}
              {phase === 'success' && queryResult?.meta.page?.truncated ? <p className="muted">{t('explorer.truncated', { count: queryResult.meta.page.limit })}</p> : null}
              {phase === 'success' && queryResult ? (
                queryResult.data.rows.length === 0 ? <p className="muted">{t('explorer.emptyQuery')}</p> : (
                  <ResultGrid columns={queryResult.data.columns} rows={queryResult.data.rows} />
                )
              ) : null}
            </>
          ) : null}
        </section>
      </div>
    </Page>
  )
}

function Structure({ columns, indexes, foreignKeys }: { columns: ExplorerColumn[]; indexes: Array<{ name: string; unique: boolean; columns: string[] }>; foreignKeys: Array<{ name: string; columns: string[]; referencedTable: string; referencedColumns: string[] }> }) {
  const { t } = useTranslation()
  return (
    <div className="explorer-structure">
      <div>
        <h3>{t('explorer.columns')}</h3>
        <ul>
          {columns.map((column) => (
            <li key={column.name}>
              <strong>{column.name}</strong> {column.type}
              {' · '}
              {column.nullable ? t('explorer.nullable') : t('explorer.required')}
              {column.primaryKey ? ` · ${t('explorer.primaryKey')}` : ''}
            </li>
          ))}
        </ul>
      </div>
      <div>
        <h3>{t('explorer.indexes')}</h3>
        {indexes.length === 0 ? <p className="muted">{t('explorer.noIndexes')}</p> : (
          <ul>
            {indexes.map((index) => (
              <li key={index.name}>{index.name}: {index.columns.join(', ')}{index.unique ? ` · ${t('explorer.unique')}` : ''}</li>
            ))}
          </ul>
        )}
      </div>
      <div>
        <h3>{t('explorer.relationships')}</h3>
        {foreignKeys.length === 0 ? <p className="muted">{t('explorer.noRelationships')}</p> : (
          <ul>
            {foreignKeys.map((key) => (
              <li key={key.name}>{key.columns.join(', ')} · {t('explorer.references', { table: key.referencedTable })} ({key.referencedColumns.join(', ')})</li>
            ))}
          </ul>
        )}
      </div>
    </div>
  )
}

function ResultGrid({ columns, rows, sort, direction, onSort }: { columns: string[]; rows: ExplorerRows['rows']; sort?: string | null; direction?: 'asc' | 'desc'; onSort?: (column: string) => void }) {
  const { t } = useTranslation()
  const format = useTenantFormat()
  return (
    <div className="table-wrap">
      <table className="data-table">
        <thead>
          <tr>
            {columns.map((column) => (
              <th key={column} scope="col">
                {onSort ? (
                  <button type="button" className="explorer-sort" onClick={() => onSort(column)} aria-sort={sort === column ? (direction === 'asc' ? 'ascending' : 'descending') : 'none'}>
                    {column}
                  </button>
                ) : column}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, index) => (
            <tr key={`${index}-${columns.join('|')}`}>
              {columns.map((column) => {
                const cell = presentExplorerValue(column, row[column] ?? null, (iso) => format.dateTime(iso))
                if (cell.kind === 'null') {
                  return <td key={column} className="muted">{t('explorer.nullValue')}</td>
                }
                if (cell.kind === 'masked') {
                  return <td key={column}>{t('explorer.masked')}</td>
                }
                return <td key={column}>{cell.text}</td>
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

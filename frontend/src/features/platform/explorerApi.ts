import { baseApi } from '../../shared/api/baseApi'

export type ExplorerDatabase = {
  target: string
  label: string
  kind: string
  status: string | null
}

export type ExplorerTableSummary = {
  name: string
  kind: string
  rowEstimate: number | null
}

export type ExplorerColumn = {
  name: string
  type: string
  nullable: boolean
  primaryKey: boolean
}

export type ExplorerIndex = {
  name: string
  unique: boolean
  columns: string[]
}

export type ExplorerForeignKey = {
  name: string
  columns: string[]
  referencedTable: string
  referencedColumns: string[]
}

export type ExplorerTableDetail = {
  name: string
  kind: string
  rowEstimate: number | null
  columns: ExplorerColumn[]
  indexes: ExplorerIndex[]
  foreignKeys: ExplorerForeignKey[]
}

export type ExplorerPage = {
  limit: number
  offset: number
  returned: number
  hasMore: boolean
  truncated?: boolean
  durationMs?: number
}

export type ExplorerRows = {
  columns: string[]
  rows: Array<Record<string, unknown>>
}

export type ExplorerRowsResponse = {
  data: ExplorerRows
  meta: { page?: ExplorerPage; durationMs?: number }
}

export type ExplorerFilter = {
  column: string
  value: string
}

export type ExplorerRowsArgs = {
  target: string
  table: string
  limit: number
  offset: number
  sort?: string
  direction: 'asc' | 'desc'
  q?: string
  columns?: string
  filters: ExplorerFilter[]
}

export type ExplorerQueryArgs = {
  target: string
  sql: string
  ticket: number
}

function rowsQuery(args: ExplorerRowsArgs): string {
  const params = new URLSearchParams()
  params.set('limit', String(args.limit))
  params.set('offset', String(args.offset))
  params.set('direction', args.direction)
  if (args.sort) {
    params.set('sort', args.sort)
  }
  if (args.q) {
    params.set('q', args.q)
  }
  if (args.columns) {
    params.set('columns', args.columns)
  }
  const query = params.toString()
  const filters = args.filters.map((filter) => `filter[${encodeURIComponent(filter.column)}]=${encodeURIComponent(filter.value)}`).join('&')
  const path = `/platform/databases/${encodeURIComponent(args.target)}/tables/${encodeURIComponent(args.table)}/rows`
  const extra = [query, filters].filter((part) => part !== '').join('&')
  return extra === '' ? path : `${path}?${extra}`
}

export const explorerApi = baseApi.injectEndpoints({
  endpoints: (builder) => ({
    getExplorerDatabases: builder.query<{ data: ExplorerDatabase[]; meta: { durationMs?: number } }, void>({
      query: () => '/platform/databases',
      providesTags: ['Explorer'],
      keepUnusedDataFor: 0,
    }),
    getExplorerTables: builder.query<{ data: ExplorerTableSummary[]; meta: { durationMs?: number } }, string>({
      query: (target) => `/platform/databases/${encodeURIComponent(target)}/tables`,
      providesTags: ['Explorer'],
      keepUnusedDataFor: 0,
    }),
    getExplorerTable: builder.query<{ data: ExplorerTableDetail; meta: { durationMs?: number } }, { target: string; table: string }>({
      query: ({ target, table }) => `/platform/databases/${encodeURIComponent(target)}/tables/${encodeURIComponent(table)}`,
      providesTags: ['Explorer'],
      keepUnusedDataFor: 0,
    }),
    getExplorerRows: builder.query<ExplorerRowsResponse, ExplorerRowsArgs>({
      query: rowsQuery,
      providesTags: ['Explorer'],
      keepUnusedDataFor: 0,
    }),
    runExplorerQuery: builder.mutation<ExplorerRowsResponse, ExplorerQueryArgs>({
      query: ({ target, sql }) => ({
        url: `/platform/databases/${encodeURIComponent(target)}/query`,
        method: 'POST',
        body: { sql },
      }),
    }),
  }),
})

export const {
  useGetExplorerDatabasesQuery,
  useGetExplorerTablesQuery,
  useGetExplorerTableQuery,
  useGetExplorerRowsQuery,
  useRunExplorerQueryMutation,
} = explorerApi

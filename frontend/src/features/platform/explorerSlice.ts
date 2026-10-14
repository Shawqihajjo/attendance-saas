import { createSlice, type PayloadAction } from '@reduxjs/toolkit'
import type { ExplorerFilter } from './explorerApi'

export type ExplorerState = {
  target: string | null
  table: string | null
  tableSearch: string
  schemaOpen: boolean
  columns: string[] | null
  sort: string | null
  direction: 'asc' | 'desc'
  q: string
  draftQ: string
  filters: ExplorerFilter[]
  offset: number
  treeOpen: boolean
  structureOpen: boolean
  queryOpen: boolean
  queryHeight: number
  queryText: string
  queryOffset: number
  generation: number
}

export const initialExplorerState: ExplorerState = {
  target: null,
  table: null,
  tableSearch: '',
  schemaOpen: false,
  columns: null,
  sort: null,
  direction: 'asc',
  q: '',
  draftQ: '',
  filters: [],
  offset: 0,
  treeOpen: true,
  structureOpen: true,
  queryOpen: true,
  queryHeight: 220,
  queryText: '',
  queryOffset: 0,
  generation: 0,
}

const explorerSlice = createSlice({
  name: 'explorer',
  initialState: initialExplorerState,
  reducers: {
    selectTarget(state, action: PayloadAction<string>) {
      if (state.target === action.payload) {
        state.treeOpen = true
        return
      }
      const generation = state.generation + 1
      const queryOpen = state.queryOpen
      const queryHeight = state.queryHeight
      const treeOpen = true
      Object.assign(state, initialExplorerState)
      state.target = action.payload
      state.generation = generation
      state.queryOpen = queryOpen
      state.queryHeight = queryHeight
      state.treeOpen = treeOpen
    },
    selectTable(state, action: PayloadAction<string>) {
      state.table = action.payload
      state.schemaOpen = true
      state.structureOpen = true
      state.columns = null
      state.sort = null
      state.direction = 'asc'
      state.q = ''
      state.draftQ = ''
      state.filters = []
      state.offset = 0
    },
    setTableSearch(state, action: PayloadAction<string>) {
      state.tableSearch = action.payload
    },
    toggleSchema(state) {
      state.schemaOpen = !state.schemaOpen
    },
    toggleTree(state) {
      state.treeOpen = !state.treeOpen
    },
    toggleStructure(state) {
      state.structureOpen = !state.structureOpen
    },
    toggleQuery(state) {
      state.queryOpen = !state.queryOpen
    },
    setQueryHeight(state, action: PayloadAction<number>) {
      state.queryHeight = action.payload
    },
    setDraftQ(state, action: PayloadAction<string>) {
      state.draftQ = action.payload
    },
    applySearch(state) {
      state.q = state.draftQ.trim()
      state.offset = 0
    },
    clearSearch(state) {
      state.q = ''
      state.draftQ = ''
      state.offset = 0
    },
    addFilter(state, action: PayloadAction<ExplorerFilter>) {
      if (state.filters.length >= 10 || action.payload.column === '' || action.payload.value === '') {
        return
      }
      state.filters = state.filters.filter((filter) => filter.column !== action.payload.column)
      state.filters.push(action.payload)
      state.offset = 0
    },
    removeFilter(state, action: PayloadAction<string>) {
      state.filters = state.filters.filter((filter) => filter.column !== action.payload)
      state.offset = 0
    },
    toggleColumn(state, action: PayloadAction<{ name: string; all: string[] }>) {
      const current = state.columns ?? action.payload.all
      const next = current.includes(action.payload.name)
        ? current.filter((name) => name !== action.payload.name)
        : [...current, action.payload.name]
      if (next.length === 0) {
        return
      }
      state.columns = next.length === action.payload.all.length ? null : next
      state.offset = 0
    },
    sortBy(state, action: PayloadAction<string>) {
      if (state.sort === action.payload) {
        state.direction = state.direction === 'asc' ? 'desc' : 'asc'
      } else {
        state.sort = action.payload
        state.direction = 'asc'
      }
      state.offset = 0
    },
    setOffset(state, action: PayloadAction<number>) {
      state.offset = Math.max(0, action.payload)
    },
    setQueryText(state, action: PayloadAction<string>) {
      state.queryText = action.payload
    },
    clearQuery(state) {
      state.queryText = ''
      state.queryOffset = 0
    },
    setQueryOffset(state, action: PayloadAction<number>) {
      state.queryOffset = Math.max(0, action.payload)
    },
  },
})

export const {
  selectTarget,
  selectTable,
  setTableSearch,
  toggleSchema,
  toggleTree,
  toggleStructure,
  toggleQuery,
  setQueryHeight,
  setDraftQ,
  applySearch,
  clearSearch,
  addFilter,
  removeFilter,
  toggleColumn,
  sortBy,
  setOffset,
  setQueryText,
  clearQuery,
  setQueryOffset,
} = explorerSlice.actions

export const explorerReducer = explorerSlice.reducer

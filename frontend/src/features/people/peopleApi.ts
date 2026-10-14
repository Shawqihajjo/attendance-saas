import { baseApi } from '../../shared/api/baseApi'

export type EmployeeRecord = {
  id: string
  employeeNumber: string | null
  name: string
  email: string | null
  phone: string | null
  departmentId: string | null
  departmentName: string | null
  teamId: string | null
  teamName: string | null
  managerEmployeeId: string | null
  managerName: string | null
  status: string
  schedule: { id: string; name: string } | null
  attendanceIdentifierSet: boolean
  attendanceCode?: string
}

type EmployeeResponse = { data: EmployeeRecord }
type EmployeeListResponse = { data: EmployeeRecord[] }
type NamedResponse = { data: { id: string; name: string; departmentId?: string | null } }
type Department = { id: string; name: string }
type Team = { id: string; name: string; departmentId: string | null }

export type EmployeeInput = {
  employeeNumber: string
  name: string
  email: string | null
  phone: string | null
  departmentId: string | null
  teamId: string | null
  managerEmployeeId: string | null
  scheduleName: string | null
}

export const peopleApi = baseApi.injectEndpoints({
  endpoints: (builder) => ({
    getEmployees: builder.query<EmployeeListResponse, void>({
      query: () => '/hr/employees',
      providesTags: ['Employees'],
    }),
    getDepartments: builder.query<{ data: Department[] }, void>({
      query: () => '/hr/departments',
      providesTags: ['Departments'],
    }),
    getTeams: builder.query<{ data: Team[] }, void>({
      query: () => '/hr/teams',
      providesTags: ['Teams'],
    }),
    createEmployee: builder.mutation<EmployeeResponse, EmployeeInput>({
      query: (body) => ({ url: '/hr/employees', method: 'POST', body }),
      invalidatesTags: ['Employees', 'Quotas'],
    }),
    updateEmployee: builder.mutation<EmployeeResponse, { id: string; body: EmployeeInput }>({
      query: ({ id, body }) => ({ url: `/hr/employees/${id}`, method: 'PATCH', body }),
      invalidatesTags: ['Employees'],
    }),
    setEmployeeStatus: builder.mutation<EmployeeResponse, { id: string; status: 'active' | 'inactive' }>({
      query: ({ id, status }) => ({ url: `/hr/employees/${id}/status`, method: 'POST', body: { status } }),
      invalidatesTags: ['Employees', 'Quotas'],
    }),
    resetAttendanceCode: builder.mutation<EmployeeResponse, string>({
      query: (id) => ({ url: `/hr/employees/${id}/attendance-code`, method: 'POST' }),
      invalidatesTags: ['Employees'],
    }),
    createDepartment: builder.mutation<NamedResponse, { name: string }>({
      query: (body) => ({ url: '/hr/departments', method: 'POST', body }),
      invalidatesTags: ['Departments'],
    }),
    createTeam: builder.mutation<NamedResponse, { name: string; departmentId: string | null }>({
      query: (body) => ({ url: '/hr/teams', method: 'POST', body }),
      invalidatesTags: ['Teams'],
    }),
  }),
})

export const {
  useCreateDepartmentMutation,
  useCreateEmployeeMutation,
  useCreateTeamMutation,
  useGetDepartmentsQuery,
  useGetEmployeesQuery,
  useGetTeamsQuery,
  useResetAttendanceCodeMutation,
  useSetEmployeeStatusMutation,
  useUpdateEmployeeMutation,
} = peopleApi

# User guide — HR

**Role:** `hr`  
**Typical paths:** `/hr/*`, `/people`

## Access

Sign in on the tenant host with HR permissions.

## Workflows

| Task | Path | Permission |
| --- | --- | --- |
| Employees | `/hr/employees`, `/people` | `employees.manage` |
| Departments | `/hr/departments` | `organization.manage` |
| Teams | `/hr/teams` | `organization.manage` |
| Schedules | `/hr/schedules` | `schedules.manage` |
| Attendance | `/hr/attendance` | `employees.manage` (list) |
| Corrections | `/hr/corrections` | `attendance.correction.manage` |
| Reports | `/hr/reports` | `reports.view` |
| Tenant audit | `/hr/audit` | `audit.view` |
| Photo / settings policies | Settings APIs / HR settings | `tenant.settings.manage` where applicable |

## Employee records

1. Sign in on the tenant host with `employees.manage` (HR or tenant admin).
2. Open **People** at `/people` or **HR → Employees** at `/hr/employees`.
3. Use the create-employee form (name and related fields). The UI calls `POST /api/v1/hr/employees`.
4. Create departments and teams on the same People page where those forms appear.
5. Generate or reset an attendance code when offered — the plaintext code is shown **once** and stored only as a keyed hash.

`app:tenant:create-user` also creates a linked employee for that login account; use People/HR for directory employees who may not have a web login.

## Reports

`GET /api/v1/hr/reports` filters by employee, team, department, date range, event type, and status, with pagination. Choose display timezone mode (`tenant`, `terminal`, `browser`, `report`) as supported by the API. Export to CSV/PDF is **not implemented** in the frontend today.

## Photographs

Authorized users may view or delete attendance photos through HR photo APIs. Retention can be configured via `PATCH /api/v1/hr/settings/photo-retention`. Bytes are not public URLs.

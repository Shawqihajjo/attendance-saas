# User guide — Chief / Manager

**Role:** `manager`  
**Typical paths:** `/team/*`

Managers see **assigned** employees only (not the whole tenant unless they also hold HR permissions).

## Access

Sign in on the tenant host with a user that has the `manager` role (or equivalent permissions).

## Workflows

| Task | Path | Permission |
| --- | --- | --- |
| Assigned employees | `/team/employees` | `employees.view_assigned` |
| Team attendance | `/team/attendance` | `attendance.view_team` |
| Review board | `/team/review` | `attendance.review` |
| Decide corrections | `/team/corrections` | `attendance.correction.decide` |
| Team reports | `/team/reports` | `reports.view_team` |

Dashboards (`GET /api/v1/hr/dashboard` / attendance APIs) scope managers to their assignments. Reports support filters and display timezone options; worked time uses `workedSeconds`.

## Approving corrections

Open pending requests for assigned employees. Approve or reject with a decision. Approval writes `attendance_adjustments` and may update the session projection; original `attendance_events` rows stay append-only.

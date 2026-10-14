# User guide — Employee

**Role:** `employee`  
**Typical paths:** `/me`, `/me/profile`, `/me/attendance`, `/me/history`, `/me/corrections`

## Access

Sign in on the **tenant** hostname with an employee account (or a higher role that includes employee permissions).

## What you can do

| Task | Path / API |
| --- | --- |
| View profile | `/me/profile` |
| Today’s attendance and worked time | `/me` dashboard (`GET /api/v1/me/dashboard`) |
| Attendance history | `/me/history` / `GET /api/v1/me/attendance` |
| Request a correction | `/me/corrections` (`attendance.correction.request`) |

Displayed times use the tenant timezone (and language preferences). Stored facts remain UTC.

## Attendance states you may see

`off`, `working`, `on_lunch` — derived from the ledger. Clocking in or out on the **web app** is not the primary path for physical attendance; the **kiosk** (`/kiosk`) records terminal events. Employees normally use `/me` to review history and request corrections.

## Corrections

Submit a request with local date, requested time/action, and reason. Status starts as `requested`. A manager or HR decides `approved` or `rejected`. Approval adds an adjustment; it does not rewrite original events.

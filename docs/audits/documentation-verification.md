# Documentation verification (fresh-install QA)

**Date:** 2026-10-14  
**Role:** Independent QA review of README and installation/usage documentation  
**Constraint:** No application source changes. No volume deletes. No production migrations.

## Summary

| Area | Status after review |
| --- | --- |
| Install sequence vs Compose/CLI | **Mostly accurate**; gaps fixed where verified |
| Commands vs repository | **Verified** against Compose services and `app:*` help |
| Fresh-clone PHPUnit step | **Was failing / undocumented** → **corrected** |
| Password / role rules | **Were incomplete** → **corrected** |
| User workflows (UI) | **Documented from code**; full UI flows **not end-to-end tested** in this pass |
| Live CD / GHCR | **Not exercised** (inactive / no host) |

**Final documentation status:** Suitable for a Docker-based fresh install after the corrections in this report. Operators must still create passwords, optionally configure `*.localhost`, and create explorer DB users manually. Product gaps (fingerprint vendor, audit UI, CSV export, live CD) remain labeled as not implemented.

---

## Tests actually performed

| Test | Result |
| --- | --- |
| `docker compose config --services` | Services: `mysql`, `rabbitmq`, `redis`, `php`, `frontend`, `messenger`, `nginx` |
| `docker compose ps` | All listed services Up; nginx/mysql/redis/rabbitmq healthy |
| `curl /api/v1/health` | HTTP 200 |
| `curl /api/v1/health/ready` | HTTP 200; checks ok / tenant unselected |
| `app:diagnostics` | JSON `status: ok` (earlier same day; stack still healthy) |
| `app:platform:create-admin --help` | Matches documented args; password ≥12 in help text |
| `app:tenant:create-user --help` | Matches documented args; `--role` multi-value |
| `app:tenant:provision --help` | Matches documented args |
| `doctrine:migrations:status` | Platform at latest (10 executed) on this volume |
| PHPUnit without `backend/.env` | **Fails** with Dotenv `PathException` (reproduced; `.env` restored) |
| `AccountInput::password` / `tenantRoles` | Code requires 12–200 chars; at least one role |
| Relative markdown links (prior delivery) | 0 missing; re-checked structure present |
| Browser login / create employee UI / kiosk / explorer UI | **Not exercised** in this pass |
| `docker compose down -v` / cold empty volume | **Not run** (destructive) |
| Production deploy | **Not run** |

---

## Issues

### DOC-01 — PHPUnit undocumented `.env` requirement on fresh clone

| Field | Value |
| --- | --- |
| **Severity** | High |
| **File / section** | `docs/installation/local-development.md` §14; `docs/development/testing.md`; `README.md` Tests; `docs/development-setup.md` |
| **Problem** | Fresh checkout has `backend/.env.test` but not `backend/.env`. PHPUnit bootstrap calls `Dotenv::bootEnv(.../.env)` and fails before any test runs. Install docs previously omitted this step. |
| **Evidence** | Reproduced: remove `.env` → `Unable to read the "/var/www/html/.env" environment file`. CI already copies `.env.test` → `.env`. |
| **Correction** | Documented `test -f .env \|\| cp .env.test .env` before PHPUnit in install, testing, README, development-setup, troubleshooting. |

### DOC-02 — Password length and required `--role` omitted

| Field | Value |
| --- | --- |
| **Severity** | Medium |
| **File / section** | `docs/installation/local-development.md` §11; auth architecture; README quick start |
| **Problem** | Docs showed example passwords but did not state the enforced **12–200** character rule or that omitting `--role` fails. |
| **Evidence** | `AccountInput::password` / `tenantRoles`; CLI help “Password of at least 12 characters”; empty roles → `At least one role is required.` |
| **Correction** | Explicit rules added to install guide, README, and authentication doc. |

### DOC-03 — Readiness presented as “stack ready” without schema caveat

| Field | Value |
| --- | --- |
| **Severity** | Medium |
| **File / section** | `docs/installation/local-development.md` §4 |
| **Problem** | `/api/v1/health/ready` succeeds when MySQL answers `SELECT 1` even if platform migrations have never been applied. A new developer might skip migrations and then fail on `create-admin` / provision. |
| **Evidence** | `ReadinessChecker::platformDatabase()` only runs `SELECT 1`. MySQL init only creates empty `platform` / `tenant_placeholder` DBs. |
| **Correction** | Clarified that readiness ≠ migrations applied; migrate section remains mandatory before admins/tenants. |

### DOC-04 — Foreground `docker compose up --build` blocks the shell

| Field | Value |
| --- | --- |
| **Severity** | Low |
| **File / section** | `docs/installation/local-development.md` §4; README quick start |
| **Problem** | Foreground up is valid but ambiguous for “same terminal” install; first `npm ci` can take minutes. |
| **Evidence** | Compose `frontend` command is `npm ci && npm run dev …`; nginx healthcheck start_period 30s. |
| **Correction** | Documented `up --build -d` as recommended; wait for nginx healthy; README quick start uses `-d`. |

### DOC-05 — `curl` used but not listed as prerequisite

| Field | Value |
| --- | --- |
| **Severity** | Low |
| **File / section** | `docs/installation/prerequisites.md` |
| **Problem** | Health probes assume `curl` is available. |
| **Evidence** | Install guide commands use `curl -sS`. |
| **Correction** | Added `curl` as recommended prerequisite. |

### DOC-06 — Employee creation path underspecified for HR workflow

| Field | Value |
| --- | --- |
| **Severity** | Medium |
| **File / section** | `docs/user-guide/hr.md` |
| **Problem** | Said “create employees” without UI path or API; fresh operators need `/people` or `/hr/employees`. |
| **Evidence** | `PeoplePage` + `POST /api/v1/hr/employees`; router guards `employees.manage`. |
| **Correction** | Step list added in HR user guide; install §11 notes linked employee from `create-user` vs People UI. |

### DOC-07 — Database Explorer setup incomplete without MySQL grants

| Field | Value |
| --- | --- |
| **Severity** | Medium (documentation honesty) |
| **File / section** | `docs/user-guide/database-explorer.md` |
| **Problem** | Docs implied explorer works after env vars; no repo script creates SELECT-only users. Empty URLs → 503. |
| **Evidence** | `.env.example` comments; deployment docs; no grant script under `docker/mysql`. |
| **Correction** | Explicitly state operators must create read-only accounts themselves; no automated bootstrap in repo. |

### DOC-08 — Cold empty-volume install not fully re-executed

| Field | Value |
| --- | --- |
| **Severity** | Info / residual risk |
| **File / section** | Entire install guide |
| **Problem** | This QA pass used an already-populated local Docker volume. A true `down -v` cold start was not performed (destructive). Timing and first-migrate edge cases on empty volume remain **manually unverified**. |
| **Evidence** | Audit constraint: do not delete volumes. |
| **Correction** | None to product; residual verification listed below. |

### DOC-09 — User workflows not browser-tested in this pass

| Field | Value |
| --- | --- |
| **Severity** | Info |
| **File / section** | `docs/user-guide/*` |
| **Problem** | Guides match routes/permissions/API from code, but login, branding save, terminal enroll, kiosk mock attendance, reports, quotas UI, and explorer UI were **not** clicked through. |
| **Evidence** | No browser automation in this audit. |
| **Correction** | Status labeled in summary; do not claim UI tested. |

### DOC-10 — Cannot document live CD / production fingerprint as working

| Field | Value |
| --- | --- |
| **Severity** | Info (product gap) |
| **File / section** | Deployment / hardware docs |
| **Problem** | Accurate documentation stops at “inactive until configured” and “unavailable adapters in prod.” |
| **Evidence** | `DEPLOY_ENABLED` gate; UnavailableFingerprintReader in prod services. |
| **Correction** | Already labeled; no invented success claims. |

---

## Fresh-install sequence review (clean computer assumptions)

| Step | Documented? | Possible as written? | Notes |
| --- | --- | --- | --- |
| Install Docker/Git | Yes | Yes | OS-specific installers not shipped (expected) |
| Clone repo | Yes | Yes | URL `Shawqihajjo/attendance-saas` |
| `cp .env.example .env` | Yes | Yes | Compose root env |
| `docker compose up --build` | Yes | Yes | Prefer `-d` (now documented) |
| Wait for health | Partial → fixed | Yes | Need healthy nginx; curl recommended |
| Platform migrate | Yes | Yes | Required before admin/tenant |
| Provision tenant | Yes | Yes | `app:tenant:provision` |
| Create admins | Yes | Yes | Password/role rules now explicit |
| Open UI hosts | Yes | Mostly | `*.localhost` may need hosts file |
| Create employees | Partial → improved | Via UI | Not seeded |
| Branding / languages | User guide | Via `/settings/*` | Not UI-tested here |
| Terminals | User guide | Via `/terminals` + kiosk | Not UI-tested here |
| Attendance (mock) | User guide | Dev mocks | Not UI-tested here |
| Reports | User guide | `/hr/reports` / `/team/reports` | Not UI-tested here |
| Quotas | Super-admin guide | Platform tenant detail | Not UI-tested here |
| Explorer | Guide + 503 caveat | Needs manual MySQL users | |
| PHPUnit | Was broken on fresh → fixed | Needs `.env` copy | Verified failure mode |

---

## Corrections applied in this review

- `docs/installation/local-development.md` — detached up, readiness caveat, password/role rules, PHPUnit `.env`, employee note  
- `docs/installation/prerequisites.md` — `curl`  
- `docs/installation/troubleshooting.md` — PHPUnit `.env`  
- `docs/development/testing.md` — PHPUnit `.env`  
- `docs/development-setup.md` — PHPUnit `.env`  
- `README.md` — `-d`, password/role, PHPUnit `.env`  
- `docs/architecture/authentication-and-permissions.md` — password/role rules  
- `docs/user-guide/hr.md` — employee creation steps  
- `docs/user-guide/database-explorer.md` — no grant automation  

No application PHP/TS/Docker **runtime** code was modified.

---

## Cannot be documented as complete until product work exists

| Topic | Blocker |
| --- | --- |
| Production fingerprint / camera | Vendor adapters unavailable in prod |
| Platform audit operator screen | API only |
| CSV/PDF export | Not in frontend |
| Automated explorer MySQL user provisioning | No script in repo |
| One-click demo seed (employees, schedules) | Not in repo |
| Live staging/production CD success | No configured host / `DEPLOY_ENABLED` |
| Default login credentials | Intentionally absent |

---

## Residual manual verification checklist

1. On a machine with **no** project volumes: `docker compose down -v` (only if disposable) → full install guide end-to-end.  
2. Browser: platform login → create/view tenant → quotas; tenant login → People → branding → terminals → kiosk mock clock-in → reports.  
3. Configure read-only explorer URLs and open `/platform/databases`.  
4. Confirm `*.localhost` resolution on the target OS.  

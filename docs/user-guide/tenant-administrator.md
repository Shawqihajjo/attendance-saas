# User guide — Tenant administrator

**Role:** `tenant_admin`  
**Host:** the tenant hostname (example `http://acme.localhost:8080`)

## Access

1. Open the tenant URL (not bare `localhost` unless that host is mapped as the tenant).
2. Sign in with a tenant user that has `tenant_admin` (created via CLI or user management).

```sh
docker compose exec php php bin/console app:tenant:create-user acme admin@acme.example "Admin" --password='…' --role=tenant_admin
```

## Employees and users

| Area | Path | Permission |
| --- | --- | --- |
| People / employees | `/people`, `/hr/employees` | `employees.manage` |
| Tenant users | `/settings/users` | `users.manage` |
| Roles | `/settings/roles` | `permissions.manage` |

Attendance codes are shown **once** when generated/reset and stored only as a keyed hash.

## Branding, languages, timezone

| Setting | Path | Permission |
| --- | --- | --- |
| Logo and colors | `/settings/branding` | `branding.manage` |
| Languages | `/settings/languages` | `locales.manage` |
| Timezone / formats | `/settings/timezone` | `timezone.manage` |
| General tenant settings | `/settings` | `tenant.settings.manage` |
| Usage vs quotas (read) | `/settings/usage` | `tenant.configuration.manage` |

Public branding for the host: `GET /api/v1/public/branding`.

## Attendance terminals

Register and enroll devices at `/settings/terminals` or `/terminals` (`terminals.manage`). Operators enter the one-time code on `/kiosk`. See [attendance-terminal.md](attendance-terminal.md).

## Supported vs not supported

| Capability | Status |
| --- | --- |
| Branding, locales (en/sv), timezone | Implemented |
| User and terminal management | Implemented |
| Quota **editing** | Platform operator only |
| Email invites / billing plans | **Not implemented** |

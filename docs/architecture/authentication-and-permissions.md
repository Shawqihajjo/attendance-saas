# Authentication and permissions

## Session authentication

| Item | Value |
| --- | --- |
| Cookie | `ATTENDANCE_SESSION` (HTTP-only, `SameSite=Lax`) |
| Store | Redis keys under `platform:session:` |
| Tenant login | `POST /api/v1/auth/login` on the **tenant** host |
| Platform login | `POST /api/v1/platform/login` on the **platform** host |
| Session | `GET /api/v1/auth/session` |
| Logout | `POST /api/v1/auth/logout` or `POST /api/v1/platform/logout` |

There is **no JWT** auth configuration in this repository.

The React client calls `GET /api/v1/tenant/context` first: **404** → treat as platform host; otherwise tenant host.

## Roles and permissions

Roles are bundles of permission strings. The **API** is authoritative; the UI only hides navigation.

| Role | Permission examples |
| --- | --- |
| `employee` | `profile.view_own`, `attendance.view_own`, `attendance.correction.request` |
| `manager` | Assigned employees, team attendance, review, correction decide, team reports |
| `hr` | Employees, organization, schedules, corrections, reports, audit, tenant settings |
| `tenant_admin` | Users, terminals, branding, locales, timezone, permissions, configuration |
| `platform_admin` | `tenants.manage`, `tenants.lifecycle`, `platform.administer`, `platform.database.explore` |

Full mapping lives in backend `AccessCatalog` / `Permission` (see also [../README.md](../README.md)).

## Creating users (CLI)

```sh
docker compose exec php php bin/console app:platform:create-admin ops@example.com "Ops" --password='…'
docker compose exec php php bin/console app:tenant:create-user acme ada@acme.example "Ada" --password='…' --role=tenant_admin
```

Passwords are supplied at creation time and are **not** stored in the repository. Application validation requires passwords of **12–200** characters. Tenant users must include **at least one** valid `--role` (`employee`, `manager`, `hr`, `tenant_admin`); omitting `--role` fails with “At least one role is required.”

## Rate limiting and CSRF

Documented behavior: repeated failed logins can return `429` `rate_limited`; bad `Origin` can return `403` `csrf_rejected`. See [../README.md](../README.md) / [../development.md](../development.md).

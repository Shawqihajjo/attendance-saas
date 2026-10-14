# Database setup (platform and tenants)

## What lives where

| Database | Contents |
| --- | --- |
| **Platform** (`MYSQL_DATABASE`, default `platform`) | Tenant registry, domains, encrypted tenant DB connection rows, quotas, branding/settings metadata, platform users, device registry, platform audit |
| **Each tenant** (`tenant_` + hex name) | Employees, users, attendance events/adjustments, departments/teams, terminal projections, photos metadata, tenant audit |
| **`tenant_placeholder`** | Empty boot URL only — application code does not use it as a real tenant |

Attendance business data **never** belongs in the platform database. Details: [../tenant-databases.md](../tenant-databases.md).

## How a tenant is identified

1. Browser or terminal sends an HTTP `Host` header (for example `acme.localhost`).
2. Platform table `tenant_domains` maps that host to a tenant.
3. The server opens that tenant’s MySQL database for the request life-cycle, then resets the connection.

Clients must **not** send a trusted `tenant_id`. Platform host (`PLATFORM_HOSTNAME`, `localhost` in development) has no tenant database selected.

## Development initialization (exact commands)

From the repository root, with Compose running:

```sh
# 1. Platform schema
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction

# 2. Create one tenant DB + run tenant migrations + activate
docker compose exec php php bin/console app:tenant:provision acme "Acme AB" acme.localhost Europe/Stockholm --locale=en

# 3. Probe
docker compose exec php php bin/console app:tenant:check --tenant=acme
```

**Successful behavior:** exit code 0; `app:tenant:list` shows the tenant; readiness still reports tenant connection **unselected** when no request is in flight.

Platform operators can also create tenants through `POST /api/v1/platform/tenants` when signed in with `tenants.lifecycle`. The HTTP body cannot supply database host, name, username, or password.

## Tenant migrations after code updates

```sh
# One tenant
docker compose exec php php bin/console app:tenant:migrate --slug=acme

# Every tenant eligible for maintenance (stops on first failure)
docker compose exec php php bin/console app:tenant:migrate --all
```

Do **not** rely on migrations at every PHP-FPM start. Controlled release order is documented in [../deployment/migrations-and-backups.md](../deployment/migrations-and-backups.md).

## Connection configuration

| Setting | Role |
| --- | --- |
| `PLATFORM_DATABASE_URL` | Doctrine default / platform |
| `TENANT_DATABASE_ADMIN_URL` | Root-level URL used only by provisioning (operator secret) |
| `TENANT_DB_CREDENTIALS_KEY` | Encrypts per-tenant passwords stored in the platform registry |
| Per-tenant row in `tenant_database_connections` | Host, port, database name, username, ciphertext password |

## Health verification

```sh
curl -sS http://localhost:8080/api/v1/health/ready
docker compose exec php php bin/console app:diagnostics
docker compose exec php php bin/console app:tenant:check --tenant=acme
```

Platform API: `POST /api/v1/platform/tenants/{slug}/database/check` (authenticated platform operator) reports reachability without returning credentials.

## Lifecycle (destructive operations)

| Action | Effect | Command / API |
| --- | --- | --- |
| Suspend | Requests stop opening the DB | `app:tenant:suspend` / HTTP suspend |
| Reactivate | Restore access | `app:tenant:reactivate` |
| Decommission / archive | Refuse further DB use; data kept until drop | `app:tenant:decommission` / HTTP archive |
| Drop database | **Deletes** the tenant MySQL database | `app:tenant:drop-database <slug> --confirm` only after decommission |

**Warning:** Drop is permanent for that database. Neighboring tenants are unaffected. Prefer suspend for temporary outages.

## Resetting a development environment

**Safe stop (keeps MySQL data volume):**

```sh
docker compose down
```

**Destructive reset (deletes Compose volumes including MySQL data):**

```sh
docker compose down -v
```

Only use `-v` when you intentionally want an empty database. You must re-run platform migrations and provision tenants again afterward.

Do **not** point these commands at production hosts or production volumes.

## Troubleshooting connection failures

| Symptom | Things to check |
| --- | --- |
| Platform migrate fails | MySQL healthy? `MYSQL_*` match Compose? |
| Provision fails | `TENANT_DATABASE_ADMIN_URL` / root password; key present; disk space |
| Tenant check fails | Tenant status `active`? Credentials `present`? |
| Wrong host → 404 `tenant_not_found` | Domain row / hostname spelling |
| Suspended → 403 `tenant_unavailable` | Reactivate if intended |

Collect diagnostics with `app:diagnostics` and container logs (`docker compose logs php mysql`). Do not paste passwords or employee photos into tickets.

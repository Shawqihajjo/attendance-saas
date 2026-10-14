# User guide — Super Admin (platform administrator)

**Role:** `platform_admin`  
**Permissions include:** `tenants.manage`, `tenants.lifecycle`, `platform.administer`, `platform.database.explore`  
**Host:** platform hostname (`localhost` in development → http://localhost:8080)

## Sign in

1. Open the **platform** URL (not a tenant subdomain).
2. Sign in with a platform account created by:

```sh
docker compose exec php php bin/console app:platform:create-admin you@example.com "Your Name" --password='…'
```

3. Successful login lands on `/platform`.

## Create a tenant

**UI:** `/platform/new` (requires `tenants.lifecycle`)  
**CLI:**

```sh
docker compose exec php php bin/console app:tenant:provision <slug> "<Legal Name>" <hostname> <IANA-timezone> --locale=en
```

Provisioning creates the tenant database, encrypts credentials, runs tenant migrations, and activates the tenant. You never supply database passwords in the HTTP API.

Optional: create the first tenant administrator with `app:tenant:create-user` or via platform APIs that accept an administrator payload (see [../README.md](../README.md)).

## Configure and monitor a tenant

Open `/platform/:slug` for details, limits, and usage.

| Task | Where |
| --- | --- |
| View usage vs quotas | Tenant detail Limits & Usage (`GET …/quotas`, usage endpoints) |
| Edit max users / employees / active devices | Requires `tenants.lifecycle`; lowering limits needs confirmation and does not delete existing rows |
| Suspend | Blocks sign-in and attendance for that tenant only |
| Reactivate | Restores access |
| Archive / decommission | Refuses further DB use; data retained until drop |
| Drop database | **Destructive** — requires confirmation equal to the slug; only after decommission |

**Warning:** Drop permanently removes that tenant’s MySQL database. Neighboring tenants are unaffected. Prefer suspend for temporary outages.

## Devices

Platform operators manage the device registry (register / activate flows). Tenant admins enroll kiosks day-to-day via `/terminals`. CLI: `app:device:register`, `app:device:publish-key`, `app:device:activate`.

## Database Explorer and SQL Console

Path: `/platform/databases` (permission `platform.database.explore`).  
Requires `EXPLORER_PLATFORM_DATABASE_URL` / `EXPLORER_TENANT_DATABASE_URL` with **read-only** accounts; empty URLs yield **503**.  
See [database-explorer.md](database-explorer.md).

## Audit

`GET /api/v1/platform/audit` is implemented for `platform.administer`. A dedicated platform audit **screen is not implemented** in the React app (API-only today).

## Provisioning errors

Failed provisioning leaves status `provisioning` and records `tenant.provision_failed` without passwords. Retry migrate/activate as documented in [../tenant-databases.md](../tenant-databases.md). Use `app:tenant:check` and database check APIs without exposing credentials.

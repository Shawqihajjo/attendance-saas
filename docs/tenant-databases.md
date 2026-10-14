# Tenant databases

Each tenant has its own MySQL database. The platform database only stores what the application needs to find that database and decide whether it may be opened. Employee, attendance, and HR rows never live in the platform database.

## Platform database

The platform connection uses `PLATFORM_DATABASE_URL`. It is the default Doctrine connection and stays open for the process.

| Table | What it stores |
| --- | --- |
| `tenants` | Id, slug, legal name, and status: `provisioning`, `active`, `suspended`, `decommissioned` |
| `tenant_domains` | Hostname that resolves to one tenant. The primary hostname is marked verified when the tenant is provisioned |
| `tenant_database_connections` | Driver, host, port, generated database name, generated username, encrypted password, key id, and whether the credentials are still present |
| `tenant_branding` | Company name, https logo and favicon URLs, and color tokens |
| `tenant_locales` | Enabled languages from the shipped catalog, with one default |
| `tenant_settings` | IANA timezone, date format, time format, first day of week, and the photo-required flag |
| `tenant_quotas` | Platform-owned `max_users`, `max_employees`, and `max_active_devices`. `NULL` means unlimited |
| `platform_users` | Platform operator accounts |
| `platform_credentials` | Password hash and token version for a platform operator |
| `platform_memberships` | Role membership for a platform user |
| `device_registry` | Device id, owning tenant, lifecycle status (`pending`, `registered`, `active`, `suspended`, `revoked`), public key, and optional metadata |
| `device_keys` | Current and retired Ed25519 public keys |
| `device_enrollment_tokens` | HMAC of a one-time registration or rotation token |
| `tenant_device_policies` | Optional allowed networks, IP ranges, and application versions |
| `platform_audit_log` | Append-only record of provisioning, suspension, reactivation, decommission, database drop, and device registration |
| `tenant_integrations` | Enabled integration kind, event name, status, and encrypted configuration. The configuration is data for the fixed action catalog |

A tenant database password is encrypted with libsodium secretbox. The key is `TENANT_DB_CREDENTIALS_KEY` (base64-encoded 32 bytes) and the key id is `TENANT_DB_CREDENTIALS_KEY_ID`. The plaintext password is not written to the audit log. Doctrine's connection log redacts passwords.

`TENANT_DATABASE_URL` is only a boot placeholder. Application code does not query that database.

## Tenant database

Provisioning creates a database named `tenant_` plus 32 hex characters and a user named `tu_` plus 16 hex characters. Those values are generated on the server. A request cannot supply a database name, host, username, or password.

The tenant schema is applied from `backend/migrations/tenant`, one tenant at a time. The attendance ledger appends events and derives `off`, `working`, and `on_lunch` from that history. Device requests are signed with Ed25519. The tables establish ownership:

| Table | Owned data |
| --- | --- |
| `departments`, `teams` | Organization structure |
| `users`, `user_credentials`, `user_roles` | Tenant web accounts, password hashes, and role codes. An active user is `status = active` |
| `quota_guard` | One row locked inside the same transaction that adds a user or employee |
| `employees`, `employee_codes`, `manager_assignments` | Employee number, name, contact details, department, team, status, one manager, and the HMAC of the attendance identifier. An active employee is `status = active`, with or without a login |
| `schedules`, `hr_notes` | Schedule labels and HR notes |
| `devices`, `device_heartbeats` | Operational projection of a platform device |
| `fingerprint_enrollments` | Template references |
| `attendance_attempts`, `attendance_sessions`, `attendance_events`, `attendance_photos`, `attendance_guards` | Append-only attendance events, the open session projection, photograph metadata (type, size, hash, driver, retention, deletion), and the per-employee commit lock |
| `attendance_correction_requests` | Correction requests. Status moves from `requested` to `approved` or `rejected`. Each row stores the requester, reviewer, original attendance snapshot, requested change, decision, comments, and UTC timestamps |
| `attendance_adjustments` | Approved corrections. These rows are additional attendance facts. `attendance_events` is not updated or deleted |
| `attendance_facts` | Read-only view of `attendance_events` and `attendance_adjustments` used by dashboards, reports, and the terminal state check |
| `idempotency_keys` | Retry keys |
| `audit_log` | Tenant audit: actor, action, resource, UTC time, request id, result, client IP, device, and metadata without secrets |
| `integration_deliveries` | Delivery result for one domain event: kind, status (`delivered`, `failed`, or `dead`), event id, tenant id, and a short error |
| `integration_notifications` | In-app notification rows created by the notification action |
| `integration_outbox` | Email messages waiting to be sent. The application does not open an SMTP connection |

Scale indexes cover an employee's event time, event type, a UTC date range, a device's events, correction status for an employee and local day, heartbeat time, and audit time. The same employee-and-type index exists on `attendance_adjustments` because `attendance_facts` reads both tables. Platform `platform_audit_log` is indexed by `occurred_at`. The query rules are in `docs/architecture.md`.

Timezone, the photograph requirement, and optional photograph retention stay in the platform `tenant_settings` row. The tenant database does not keep a second copy, and employees do not store their own timezone. Image bytes are outside both databases. See `docs/media-storage.md`.

`employee_codes.code` stores the HMAC-SHA256 of the attendance identifier, not the identifier itself. The typed code is returned once by the HR API when it is generated or reset. Presenting that code identifies an employee record. It does not verify a fingerprint.

## Resolution

`GET /api/v1/tenant/context` resolves the tenant from the HTTP `Host` header and then opens that tenant's database.

Accepted sources, in combination:

1. `Host` must match a verified `tenant_domains` row.
2. A server session key `tenant_id`, when a session exists, must equal that tenant. The session is not read from the browser as authority by itself.
3. An active `device_registry` row supplies the tenant id for device calls. `pending`, `registered`, `suspended`, and `revoked` devices do not resolve.

Ignored as authority:

- `tenant_id` or `tenantId` in the query string or JSON body
- `X-Tenant-Id`
- `X-Forwarded-Host`. It is not a trusted proxy header, so origin checks use `Host` as well
- `database`, `databaseName`, `dbname`, or `password` in a JSON body

If any of those values is present and is neither the resolved tenant id nor the resolved slug, the request is rejected with `403` and code `tenant_mismatch`. The platform hostname (`PLATFORM_HOSTNAME`, `localhost` in development) is not a tenant. An unknown host returns `404` with code `tenant_not_found`. A tenant that is not `active` returns `403` with code `tenant_unavailable`.

The context payload returns the slug and the tenant configuration: company name, logo, favicon, colors, languages, IANA timezone, date format, time format, first day of week, and attendance photo policy. It does not return a database name, a credential, or a tenant id the client can send back as authority. `GET /api/v1/public/branding` returns the same configuration without the slug. On the platform host it returns the default configuration. On an unknown host it returns `404`. A tenant that is not active returns `403`.

## Connection selection

`TenantDatabaseSwitcher::switchTo()` loads the registry row, requires status `active`, decrypts the password, closes any previous tenant PDO, clears the tenant entity manager, and points `TenantAwareConnection` at that database. The MySQL session time zone is set to `+00:00`. `switchForMaintenance()` allows `provisioning`, `active`, and `suspended`. `decommissioned` is refused in both cases.

`reset()` clears the tenant entity manager, closes the PDO, wipes the password from the connection parameters, and drops the in-memory tenant context. It runs at the end of every HTTP request and in a worker `finally` block. The connection refuses SQL until the next `switchTo()`. Persistent PDO is disabled. There is no tenant connection pool.

A Messenger worker resets, switches only when the envelope has `ReceivedStamp` and a server-created `TenantStamp`, runs the handler, and resets again. Dispatch during a request does not drop the request's tenant connection. A stamp for an unknown or non-active tenant is rejected and the connection is left closed.

`app:tenant:check --tenant=slug` is the tenant-aware console command. It resolves the slug through the registry, opens that database, runs `SELECT 1`, and resets in a `finally` block.

## Lifecycle

Commands, run inside the PHP container:

```sh
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:tenant:provision acme "Acme AB" acme.localhost Europe/Stockholm --locale=en
php bin/console app:tenant:migrate --slug=acme
php bin/console app:tenant:migrate --all
php bin/console app:tenant:suspend acme
php bin/console app:tenant:reactivate acme
php bin/console app:tenant:decommission acme
php bin/console app:tenant:drop-database acme --confirm
php bin/console app:device:register acme "Front door"
php bin/console app:device:activate <device-id>
php bin/console app:tenant:list
php bin/console app:tenant:check --tenant=acme
```

`provision` creates the MySQL database and user with `TENANT_DATABASE_ADMIN_URL`, stores the encrypted password, runs the tenant migrations, and marks the tenant `active`. The admin URL is an operator secret. It is not accepted from an HTTP request. `POST /api/v1/platform/tenants` runs this same service for a `platform_admin` session. The body cannot supply a database host, name, username, or password. The response does not contain them either.

`suspend` and `decommission` stop `switchTo()` from opening the database. `drop-database` is allowed only after decommission and only with `--confirm`. The HTTP equivalents are `POST /api/v1/platform/tenants/{slug}/suspend`, `/reactivate`, `/archive`, and `/drop-database`. Archive and drop require JSON `confirmation` equal to the slug. Decommission does not drop data by itself. A suspended or archived tenant cannot sign in or use attendance. The neighboring tenant is unaffected.

Failed provisioning leaves the tenant in `provisioning` and appends `tenant.provision_failed` without the password. `app:tenant:migrate --slug=` or `POST /api/v1/platform/tenants/{slug}/activate` can be run again; it recreates the database if needed, migrates, and activates a tenant that is still provisioning. The HTTP response is an error when that retry fails, and the status stays `provisioning`. `POST /api/v1/platform/tenants/{slug}/database/check` opens the database for maintenance, runs `SELECT 1`, and reports `reachable` without returning a credential. `GET /api/v1/platform/audit` reads the platform audit for an operator with `platform.administer`.

Every lifecycle change appends a `platform_audit_log` row. The log is not updated or deleted by these commands.

## Local development

`TENANT_DATABASE_DRIVER=mysql` in Docker. Tests set the driver to `sqlite` and use a file per tenant under `backend/var/tenant-databases`. SQLite is refused when `APP_ENV=prod`.

The local credentials key in `.env.example` is for this development stack only. Replace `TENANT_DB_CREDENTIALS_KEY` and `TENANT_DATABASE_ADMIN_URL` before any shared or production deployment. Production refuses that key, the SQLite driver, and a debug boot. The deploy order is platform migrations, then `app:tenant:migrate --all`, then the web and worker processes. `docs/deployment.md` describes staging, production, and the backup of every tenant database.

For a tenant hostname such as `acme.localhost`, call the API with that Host header. Nginx already forwards `Host` to PHP. `localhost` remains the platform host and does not open a tenant database.

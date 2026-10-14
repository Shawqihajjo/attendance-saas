# Development environment

The local stack is Nginx, PHP-FPM, a Messenger worker, MySQL, Redis, RabbitMQ, and the Vite dev server. Each tenant receives its own MySQL database. Web sign-in, attendance recording, and the terminal kiosk are implemented. The product and architecture map is [docs/README.md](README.md). Tenant operations are described in `docs/tenant-databases.md`.

## Prerequisites

- Docker with Compose v2

## Start

From the repository root:

```sh
cp .env.example .env
docker compose up --build
```

`.env.example` contains local development passwords and a local tenant-credential key only. Replace `APP_SECRET`, the database passwords, and `TENANT_DB_CREDENTIALS_KEY` before any shared or production deployment. Compose passes them into the containers. Do not commit `.env`. Staging and production use `docker-compose.prod.yml` and a private env file. That layout, the health checks, the worker, backups, and the migration order are in `docs/deployment.md`.

When the stack is up:

| URL | What it is |
| --- | --- |
| http://localhost:8080 | React application |
| http://localhost:8080/api/v1/health | Liveness. No dependencies |
| http://localhost:8080/api/v1/health/ready | Platform database, tenant connection guard, Redis, and RabbitMQ |
| http://localhost:15672 | RabbitMQ management UI, local user from `.env` |

The API is served by Nginx to PHP. The browser UI is proxied to Vite. Browser calls use `/api/v1` on the same origin, so the client does not send a tenant id.

HR and tenant administrators with `employees.manage` open People at `/people` on the tenant host. They create and edit employees, departments, and teams there. The attendance code is shown once and is stored only as a keyed hash.

`GET /api/v1/public/branding` returns the configuration for the request host: company name, logo, favicon, colors, languages, timezone, date and time formats, the first day of the week, and the attendance photo policy. `http://localhost:8080` is the platform host and uses the default configuration. A tenant host such as `http://acme.localhost:8080` uses that tenant's configuration. A tenant administrator changes branding, languages, timezone, and regional settings from `/settings`. Employees, managers, and HR use `/me`, `/team`, and `/hr`. The navigation follows permissions from `GET /api/v1/auth/session`. The API remains the authority for every action. The React client applies the colors, logo, and style tokens as CSS variables before the signed-in shell or the kiosk is shown, and refetches them after a save. English and Swedish catalogs ship with the app. A signed-in user keeps a language preference, and a terminal keeps a separate one. Both are limited to the languages that tenant has enabled. Dates, times, and numbers follow that language and the tenant's date and time formats.

Stop the stack with `docker compose down`. Database files stay in the `mysql_data` volume.

## Diagnostics

Inside the PHP container:

```sh
docker compose exec php php bin/console app:diagnostics
```

Readiness returns `ok` when the platform database, Redis, and RabbitMQ answer, and when the tenant connection is `unselected`. A selected tenant connection during this check is a failure. Redis and RabbitMQ are reported as `disabled` when their URLs are empty or not AMQP, which is how the test environment runs.

## API responses

Successful JSON:

```json
{ "data": {}, "meta": {} }
```

Errors use `application/problem+json` with `type`, `title`, `status`, `detail`, `code`, `instance`, and `requestId`. Validation errors add `errors`. Anonymous calls to `/api/v1/platform`, `/api/v1/terminal`, and `/api/v1/hr` return `401` with code `unauthenticated`.

`GET /api/v1/public/branding` returns the default theme and locales. `GET /api/v1/auth/session` returns the current session, or an anonymous session when the cookie is absent. `POST /api/v1/auth/login` signs in a tenant user on that tenant's host. `POST /api/v1/platform/login` signs in a platform administrator on the platform host (`localhost`). Passwords are supplied when the account is created and are not stored in the repository:

```sh
docker compose exec php php bin/console app:platform:create-admin ops@example.com "Ops" --password='choose-a-long-password'
docker compose exec php php bin/console app:tenant:create-user acme ada@acme.example "Ada" --password='choose-a-long-password' --role=employee
```

After pulling a new tenant migration, apply it to existing databases with `docker compose exec php php bin/console app:tenant:migrate --all`.

## Data stores

MySQL starts with a `platform` database and an empty `tenant_placeholder` database. `tenant_placeholder` is only the boot URL. Provisioning creates a separate database and user for each tenant.

Apply the platform schema, then create a tenant. The tenant command runs the tenant migrations on that database and closes the connection when it finishes:

```sh
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/console app:tenant:provision acme "Acme AB" acme.localhost Europe/Stockholm --locale=en
docker compose exec php php bin/console app:tenant:check --tenant=acme
```

`GET /api/v1/tenant/context` with `Host: acme.localhost` returns that tenant. `Host: localhost` is the platform host and does not open a tenant database. A selected tenant connection during the readiness check is still a failure, because readiness runs with no tenant selected.

Redis is the application cache and the session handler. Session keys use the prefix `platform:session:`. The cookie name is `ATTENDANCE_SESSION`. It is HTTP-only and `SameSite=Lax`. `Secure` follows the request scheme, so local HTTP does not mark it secure. Protected routes read that server-side session on every request. Anonymous calls still fail closed.

The Messenger worker consumes the `async` transport and pulls one message at a time. Tenant switching runs only for messages received from the transport, so dispatching during an HTTP request does not drop an open tenant connection. Its `var/cache` is a separate volume. PHP-FPM and the worker otherwise share the project directory, and a debug container rebuild removes the previous hashed container directory. The worker still has that container loaded until it exits, so shutdown would fail while loading the console error listener.

## Tests and static analysis

The scenario map is in `docs/testing.md`. Backend tests use SQLite and do not require Redis or RabbitMQ:

```sh
docker compose exec php php vendor/bin/phpunit
docker compose exec php php vendor/bin/phpstan analyse --memory-limit=512M
```

Warm the test container before PHPStan if `var/cache/test` is missing:

```sh
docker compose exec php php bin/console cache:warmup --env=test
```

Frontend:

```sh
docker compose exec frontend npm test
docker compose exec frontend npm run lint
docker compose exec frontend npx tsc -b --pretty false
```

## Time model

Authoritative timestamps are UTC. The API writes them as `2026-10-07T06:42:15Z`. The database stores `2026-10-07 06:42:15` after `UtcTime` converts the instant to UTC. MySQL sessions use `+00:00`, and PHP's default timezone is UTC. A tenant, a terminal, a browser, and a report each supply an IANA timezone for display only. `GET /api/v1/hr/reports` accepts `display` (`tenant`, `terminal`, `browser`, `report`), `timezone`, `browserTimezone`, `deviceId`, and `date`. The diagnostics page shows the server instant in the tenant zone, the browser zone, or an explicit report zone. Fixed offsets are rejected. The model is documented in `docs/architecture.md`.

## Attendance state machine

`AttendanceLedger` appends `clock_in`, `lunch_start`, `lunch_end`, and `clock_out` only when the employee's event history allows that step. States are `off`, `working`, and `on_lunch`. Timestamps are stored in UTC. A repeated idempotency key for the same device returns the original event. The state machine is documented in `docs/architecture.md`.

## Terminal kiosk

`/kiosk` on a tenant host is the attendance terminal. It does not link to administration. The idle screen asks for a scan or a typed attendance code. The server identifies the person in that terminal's tenant, checks the fingerprint, names the legal attendance action, and records the event with the server clock. A missing code and an inactive employee share one generic message. A photograph is captured only when the server says the action needs one. Success shows the name, the recorded action, and the local terminal time, then returns to idle. Fingerprint, camera, and network failures can be retried without a second attendance event. The same attempt and action keep one idempotency key. If the readiness check fails, the idle screen waits instead of accepting a code.

The page reads a device credential from the browser's local storage key `terminal.credential`: `deviceId` is the platform device id and `seed` is the base64 Ed25519 seed. A tenant administrator registers the terminal at `/terminals` and enters the one-time code on the kiosk. The browser generates the key, sends the public key, and stores the seed. The same screen accepts a later rotation code.

The CLI still registers a pending device, publishes a public key, and activates it:

```sh
docker compose exec php php bin/console app:device:register acme "Front desk"
docker compose exec php php bin/console app:device:publish-key <device-id> <base64-public-key> --timezone=Europe/Stockholm
docker compose exec php php bin/console app:device:activate <device-id>
```

Without `window.attendanceHardware`, the development kiosk uses a mock fingerprint reader and a mock camera. The mock sample is accepted only in development and test. The hardware bridge is described in `docs/terminal-hardware.md`.

An enrolled kiosk sends `POST /api/v1/terminal/heartbeats` on load and once a minute. Attendance is refused unless the device is authenticated, present, `active`, bound to the host tenant, and inside any configured IP, network, or application-version policy. MAC address and process id are recorded only as optional metadata, or discarded. Neither is a credential: a MAC address can be changed by the machine that sends it, and a process id is a reused operating-system number with no secret. The reasons are in `docs/architecture.md`.

## Attendance photographs

The terminal upload still returns `{ photoId }`. HR and the photographed employee read the image at `GET /api/v1/hr/attendance-photos/{id}`. Users who manage employees delete it with `DELETE` on that path. HR sets retention with `PATCH /api/v1/hr/settings/photo-retention` and `{ "retentionDays": 30 }` or `null`. The bytes stay in `var/photos` during local development. The web server does not publish that directory. The replaceable storage design is in `docs/media-storage.md`.

## Management application

Signed-in tenant users open the area their permissions allow:

| Area | Path | Examples |
| --- | --- | --- |
| Employee | `/me` | Today’s attendance, history, profile, correction request |
| Manager | `/team` | Team attendance, assigned employees, review, approvals, team reports |
| HR | `/hr` | Employees, departments, teams, schedules, attendance, corrections, reports, audit |
| Tenant administrator | `/settings` | Settings, branding, languages, timezone, users, roles, terminals |
| Platform administrator | `/platform` | Tenant management: list, create, details, and lifecycle. Diagnostics stay on `/` |

`GET /api/v1/me/attendance` returns that employee’s ledger in the tenant timezone. `GET /api/v1/me/dashboard` adds the current state, today’s clock-in, lunch, clock-out, worked seconds, and a page of history. `GET /api/v1/hr/dashboard` is the manager and HR board: who is working, on lunch, or not clocked in, the selected day’s attendance, missing clock-outs, and pending corrections. A manager sees assigned employees. `GET /api/v1/hr/attendance` returns the tenant ledger for HR and the assigned team for a manager. `GET /api/v1/hr/reports` filters by employee, team, department, date range, event type, and status, and pages with `limit` and `offset`. The report timezone is presentation only. Worked time on these pages comes from `workedSeconds`, not from the displayed clock text. `POST /api/v1/me/attendance-corrections` stores a request with the local date, requested time and action, reason, optional comment, and optional supporting note. The status starts at `requested`. A manager decides assigned employees, and HR decides the tenant, with `approved` or `rejected`. Approval writes an `attendance_adjustments` row and can close or open the session projection. It does not update or delete `attendance_events`. Dashboards and reports read events and adjustments together. Form input is local component state. Shared client state is the session, branding, and kiosk attempt.

`GET /api/v1/hr/integrations` and `POST /api/v1/hr/integrations` let a tenant administrator enable a webhook, notification, email outbox, internal audit action, or the shared attendance queue for one domain event. The body is text configuration. The response lists the kind and event name, not the stored configuration. The event names and delivery rules are in `docs/domain-events.md`.

Sign-in and terminal authentication failures are rate limited. Audit rows for login, logout, employee changes, corrections, roles, tenant settings, devices, photographs, and configuration reads include the tenant, actor, result, request id, and client IP. The contract is in `docs/architecture.md`.

Attendance, correction, and audit lists accept `limit` and `offset`. The employee directory and the user list are each one SQL join. Dashboard counts are one pass over the scoped roster. The indexes and the decision not to cache tenant resolution are in `docs/architecture.md`.

## Not implemented yet

A production fingerprint vendor is not connected. Development and tests use `MockFingerprintAdapter` and `MockCameraAdapter`. Production reports `device_unavailable` until a `FingerprintReader` is configured. The steps for adding that adapter are in `docs/terminal-hardware.md`. A stored certificate reference does not authenticate; Ed25519 request signing is the device credential. Terminal-assertion signatures from a vendor SDK are specified in `docs/architecture.md` and are not implemented. An approved correction adds an adjustment. It does not rewrite an attendance event.

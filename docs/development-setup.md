# Development setup

This guide gets a developer from a clean checkout to a running local stack, tests, and a basic understanding of CI/CD. Deeper product and architecture notes stay in [README.md](README.md) and [architecture.md](architecture.md). Day-to-day environment details are also in [development.md](development.md).

## Overview

The repository is a monorepo:

| Path | Role |
| --- | --- |
| `backend/` | Symfony 7.4 API (PHP 8.4) |
| `frontend/` | React client (Vite, Redux Toolkit, i18next) |
| `docker/` | PHP, Nginx, MySQL init, deploy and backup scripts |
| `docs/` | Architecture, testing, deployment, tenant databases |

Each tenant has its own MySQL database. The platform database holds the tenant registry, quotas, device registry, and platform operators. Attendance facts never live in the platform database.

## Prerequisites

- Docker with Compose v2
- Enough disk for images and the MySQL volume

You do not need a host PHP or Node install for the default workflow. The Compose services provide them.

## Local setup

1. Copy the example environment file. Never commit the real `.env`.

```sh
cp .env.example .env
```

2. Start the stack from the repository root:

```sh
docker compose up --build
```

3. Open:

| URL | Purpose |
| --- | --- |
| http://localhost:8080 | Application (Vite via Nginx) |
| http://localhost:8080/api/v1/health | API liveness |
| http://localhost:8080/api/v1/health/ready | Platform DB, Redis, RabbitMQ, tenant connection unselected |
| http://localhost:15672 | RabbitMQ management (local credentials from `.env`) |

`.env.example` contains **local-only** placeholders. Staging and production use a private file created from `.env.prod.example`. See [deployment.md](deployment.md).

## Database initialization

MySQL starts with the `platform` database and an empty `tenant_placeholder` database (boot URL only).

Apply the platform schema, then provision a tenant (creates that tenant’s database, encrypts its password, runs tenant migrations):

```sh
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/console app:tenant:provision acme "Acme AB" acme.localhost Europe/Stockholm --locale=en
docker compose exec php php bin/console app:tenant:check --tenant=acme
```

After pulling a new tenant migration, update every existing tenant database:

```sh
docker compose exec php php bin/console app:tenant:migrate --all
```

Do not drop tenant databases during routine development. Suspend and archive keep the database; permanent drop is a separate, confirmed operation.

## Required environment variables

### Root `.env` (Compose)

Documented in `.env.example`:

| Variable | Purpose |
| --- | --- |
| `APP_SECRET` | Symfony secret (local placeholder only) |
| `PLATFORM_HOSTNAME` | Host that is the platform, not a tenant (`localhost` in development) |
| `MYSQL_*` | Platform database and MySQL users |
| `RABBITMQ_*` | Broker credentials |
| `TENANT_DB_CREDENTIALS_KEY` | Base64 32-byte key that encrypts tenant DB passwords |
| `TENANT_DB_CREDENTIALS_KEY_ID` | Key name (`dev` only for local) |

Compose builds `PLATFORM_DATABASE_URL`, `TENANT_DATABASE_URL`, `REDIS_URL`, and `MESSENGER_TRANSPORT_DSN` for the PHP containers.

Optional (usually empty locally until read-only accounts exist):

| Variable | Purpose |
| --- | --- |
| `EXPLORER_PLATFORM_DATABASE_URL` | SELECT-only explorer account for the platform DB |
| `EXPLORER_TENANT_DATABASE_URL` | SELECT-only explorer account for `tenant_*` databases |

Never put the application MySQL password into the explorer URLs.

### Staging / production

Use `.env.prod.example` as the checklist. Fill every blank from a secret store. Additional required values include `REDIS_PASSWORD`, `DEFAULT_URI` (`https://…`), and a non-`dev` credentials key id. Full rules are in [deployment.md](deployment.md).

## Test commands

Tests do not need a fingerprint reader, camera, Redis, or RabbitMQ. Backend PHPUnit forces SQLite.

```sh
docker compose exec php php vendor/bin/phpunit
docker compose exec php php vendor/bin/phpstan analyse --memory-limit=512M
docker compose exec frontend npm test
docker compose exec frontend npm run lint
docker compose exec frontend npx tsc -b --pretty false
```

Details and critical scenarios: [testing.md](testing.md).

## Architecture (short)

- **API:** Symfony, Doctrine, security voters, Messenger consumers.
- **UI:** React, route guards based on server permissions, tenant branding via CSS variables.
- **Data:** Platform MySQL + one MySQL database per tenant; Redis sessions; RabbitMQ domain events.
- **Edge:** Nginx proxies `/api/` to PHP-FPM and the UI to Vite (dev) or static assets (prod image).
- **Time:** UTC is stored; tenants choose an IANA timezone for display and reports.
- **Terminal:** Kiosk uses attendance code → fingerprint port → photograph → server-validated state transition.

## CI/CD overview

Pull request and `main`/`develop` checks are defined in `.github/workflows/ci.yml`. Details: [ci.md](ci.md).

1. **CI (automated):** PHPUnit, PHPStan, Composer validate and audit, Vitest, ESLint, TypeScript, frontend production build, Compose config validation, production image builds (no push, no deploy).
2. **Main / tags (later):** Publish PHP and Nginx images to a registry.
3. **Staging / production (later):** Run `docker/deploy/release.sh` with a private env file: platform migrations, then `app:tenant:migrate --all`, then start app and worker, then `app:diagnostics`.
4. **Rollback:** Redeploy the previous image. Do not run Doctrine down migrations against production tenant data.

Never point CI at production tenant databases. PHPUnit’s SQLite configuration is the safe default for automated checks.

## Secrets and local data (do not commit)

Excluded by `.gitignore` / `.dockerignore` (see those files):

- `.env`, `.env.prod`, `.env.staging`, `backend/.env`
- TLS material under `docker/nginx/certs/`
- `backend/var/` (photos, logs, local SQLite, tenant SQLite files)
- `backend/vendor/`, `frontend/node_modules/`, `frontend/dist/`
- Database dumps (`*.sql`, `*.sql.gz`, `backups/`)
- IDE metadata (`.idea/`)

If a secret was ever committed to Git history, removing it from the tree is not enough. Rotate the credential and treat history rewrite as a separate, explicit operation.

## Create local admin accounts

Passwords are supplied at creation time and are not stored in the repository:

```sh
docker compose exec php php bin/console app:platform:create-admin ops@example.com "Ops" --password='choose-a-long-password'
docker compose exec php php bin/console app:tenant:create-user acme ada@acme.example "Ada" --password='choose-a-long-password' --role=tenant_admin
```

## Next documents

| Document | Topic |
| --- | --- |
| [development.md](development.md) | Diagnostics, sessions, branding, Messenger |
| [tenant-databases.md](tenant-databases.md) | Provisioning, isolation, migrations |
| [testing.md](testing.md) | Test layers and critical scenarios |
| [deployment.md](deployment.md) | Staging, production, backups, rollback |

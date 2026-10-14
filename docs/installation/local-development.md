# Local development — install from scratch

This guide installs the attendance SaaS on a **clean development machine** using Docker Compose. Commands run from the **repository root** unless noted.

Related: [prerequisites.md](prerequisites.md) · [environment-configuration.md](environment-configuration.md) · [database-setup.md](database-setup.md) · [troubleshooting.md](troubleshooting.md)

## What you will have when finished

- Running containers: `nginx`, `php`, `messenger`, `frontend`, `mysql`, `redis`, `rabbitmq`
- Platform schema applied
- One sample tenant (optional but recommended)
- A platform administrator and a tenant administrator **you create** (passwords chosen by you)
- UI at http://localhost:8080

## 1. Install prerequisites

Install Docker Desktop (or Docker Engine) with Compose v2, and Git. Confirm:

```sh
docker --version
docker compose version
git --version
```

Success: each command prints a version. Failure: install Docker/Git for your OS, then retry.

## 2. Clone the repository

Replace the URL if you use SSH or a fork:

```sh
git clone https://github.com/Shawqihajjo/attendance-saas.git
cd attendance-saas
```

(If your local folder name differs, for example `attendnce_employee`, use that directory as the root for all following commands.)

## 3. Create local environment configuration

```sh
cp .env.example .env
```

**What this does:** Creates a gitignored `.env` that Compose reads. Values in `.env.example` are **local placeholders only**.

**Do not** commit `.env`. **Do not** reuse these values for staging or production.

Open `.env` and review at least `APP_SECRET`, MySQL passwords, RabbitMQ passwords, and `TENANT_DB_CREDENTIALS_KEY`. For a private laptop, the example values are enough to boot. For a shared machine, replace them first (see [environment-configuration.md](environment-configuration.md)).

## 4. Start Docker services

Foreground (logs in this terminal; use a second terminal for later commands):

```sh
docker compose up --build
```

Or detached (recommended for a clean install so the same shell stays free):

```sh
docker compose up --build -d
```

**What this does:**

- Builds the development PHP image (`attendance-php:dev`)
- Starts MySQL, Redis, RabbitMQ, PHP-FPM, Messenger worker, Vite frontend, and Nginx
- Runs `composer install` inside PHP if `vendor/` is missing (`docker/php/entrypoint.sh`)
- Runs `npm ci` and `npm run dev` inside the frontend container (first start can take several minutes)

Wait until Nginx is healthy (`docker compose ps` shows `nginx` healthy), then:

```sh
curl -sS http://localhost:8080/api/v1/health
curl -sS http://localhost:8080/api/v1/health/ready
```

Expected: HTTP 200 and JSON with `"status":"ok"`. Readiness reports `platform_database`, `redis`, and `rabbitmq` as `ok`, and `tenant_connection` as `unselected`.

**Important:** Readiness only checks that MySQL accepts `SELECT 1` (and Redis/RabbitMQ). It does **not** mean platform migrations have run. You must still apply migrations in the next section before creating admins or tenants.

**If it fails:** See [troubleshooting.md](troubleshooting.md) (ports, Docker not running, Compose errors). `curl` is used here; install it if your OS does not provide it.

Stop later with `docker compose down` (volumes kept). **Destroying data:** `docker compose down -v` deletes the MySQL volume — see warnings in [database-setup.md](database-setup.md).

## 5. Verify service health (optional but recommended)

```sh
docker compose ps
docker compose exec php php bin/console app:diagnostics
```

`app:diagnostics` should print JSON with `"status": "ok"`.

| URL | Purpose |
| --- | --- |
| http://localhost:8080 | Application UI |
| http://localhost:8080/api/v1/health | Liveness |
| http://localhost:8080/api/v1/health/ready | Readiness |
| http://localhost:15672 | RabbitMQ management UI (credentials from `.env`) |

## 6. Backend dependencies

Handled automatically by the PHP entrypoint when `vendor/autoload.php` is missing. To force a reinstall:

```sh
docker compose exec php composer install --no-interaction --prefer-dist
```

## 7. Frontend dependencies

Handled by the frontend service command (`npm ci && npm run dev …`). To reinstall manually:

```sh
docker compose exec frontend npm ci
```

## 8–10. Platform database and first tenant

MySQL init creates the `platform` database (name from `MYSQL_DATABASE`, default `platform`) and a `tenant_placeholder` boot database. Apply platform migrations, then provision a tenant:

```sh
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/console app:tenant:provision acme "Acme AB" acme.localhost Europe/Stockholm --locale=en
docker compose exec php php bin/console app:tenant:check --tenant=acme
```

**What this does:**

- Platform migrations create the registry schema
- `app:tenant:provision` creates a dedicated MySQL database and user, encrypts the password with `TENANT_DB_CREDENTIALS_KEY`, runs **tenant** migrations, and marks the tenant active
- `app:tenant:check` opens that tenant DB, runs a probe, and closes the connection

**Successful behavior:** Commands exit 0; `app:tenant:check` does not error. List tenants:

```sh
docker compose exec php php bin/console app:tenant:list
```

Details: [database-setup.md](database-setup.md) and [../tenant-databases.md](../tenant-databases.md).

## 11. Create administrators (required for login)

There are **no seeded default passwords** in the repository. Rules enforced by the application:

- `--password` is **required** and must be **12–200 characters**
- `app:tenant:create-user` requires **at least one** `--role=` (`employee`, `manager`, `hr`, or `tenant_admin`)

```sh
docker compose exec php php bin/console app:platform:create-admin ops@example.com "Ops" --password='choose-a-long-password'
docker compose exec php php bin/console app:tenant:create-user acme ada@acme.example "Ada" --password='choose-a-long-password' --role=tenant_admin
```

Replace emails and passwords. You may pass multiple roles (`--role=hr --role=tenant_admin`). `app:tenant:create-user` also creates a linked **employee** row for that login. Additional employees (without login) are created in the UI at `/people` or `/hr/employees` after sign-in. See [../architecture/authentication-and-permissions.md](../architecture/authentication-and-permissions.md).

## 12. Open the application

| Host | URL | Who signs in |
| --- | --- | --- |
| Platform | http://localhost:8080 | Platform admin (`ops@example.com` in the example above) |
| Tenant | http://acme.localhost:8080 | Tenant user (for example `ada@acme.example`) |

The UI uses `Host` to choose platform vs tenant. On the platform host, login posts to `/api/v1/platform/login`. On a tenant host, login posts to `/api/v1/auth/login`.

## 13. Verify the application is operational

1. Open http://localhost:8080/api/v1/health → `ok`
2. Sign in as platform admin → land on `/platform` (requires `tenants.manage`)
3. Open http://acme.localhost:8080 → sign in as tenant admin → `/settings` and related areas
4. Optional: open `/kiosk` on the tenant host (terminal UI; needs device enrollment for live commits)

## 14. Run automated tests

Backend tests use **SQLite** and do not need Redis, RabbitMQ, or live tenant MySQL. PHPUnit’s bootstrap still requires a `backend/.env` file (Symfony Dotenv `bootEnv`). On a fresh clone that file is **not** present until you create it:

```sh
docker compose exec php sh -c 'test -f .env || cp .env.test .env'
docker compose exec php php vendor/bin/phpunit
docker compose exec php php bin/console cache:warmup --env=test --no-interaction
docker compose exec php php vendor/bin/phpstan analyse --memory-limit=512M --no-progress
```

Frontend:

```sh
docker compose exec frontend npm test
docker compose exec frontend npm run lint
docker compose exec frontend npx tsc -b --pretty false
```

More detail: [../development/testing.md](../development/testing.md).

## Missing bootstrap capabilities (documented honestly)

| Capability | Status |
| --- | --- |
| Create platform admin | **Implemented** — `app:platform:create-admin` |
| Create tenant user | **Implemented** — `app:tenant:create-user` |
| Seed demo company / sample employees | **Not in repository** — create via UI/API after login |
| Default password in git | **Intentionally absent** |

## Next documents

- [environment-configuration.md](environment-configuration.md)
- [../user-guide/super-admin.md](../user-guide/super-admin.md)
- [../development/github-and-ci-cd.md](../development/github-and-ci-cd.md)

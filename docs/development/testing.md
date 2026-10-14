# Testing guide

Never point these commands at production databases. Backend PHPUnit forces **SQLite**, disables Redis, and uses an in-memory Messenger transport (`backend/phpunit.dist.xml`).

Scenario map: [../testing.md](../testing.md).

## Prepare (Docker)

```sh
docker compose up -d
```

Composer/npm installs are handled by containers.

**Required for PHPUnit:** `backend/tests/bootstrap.php` calls Symfony Dotenv `bootEnv` on `backend/.env`. A fresh checkout has only `backend/.env.test`. Create `.env` before tests (same pattern as CI):

```sh
docker compose exec php sh -c 'test -f .env || cp .env.test .env'
```

Without that file, PHPUnit fails with `Unable to read the ".../.env" environment file`.

For PHPStan, warm the test container if needed:

```sh
docker compose exec php php bin/console cache:warmup --env=test --no-interaction
```

## Backend

```sh
# Unit + API/integration suite (SQLite)
docker compose exec php php vendor/bin/phpunit

# Or composer script
docker compose exec php composer test

# Static analysis
docker compose exec php php vendor/bin/phpstan analyse --memory-limit=512M --no-progress

# Syntax (also in CI)
docker compose exec php sh -c "find src tests -name '*.php' -print0 | xargs -0 -n1 php -l"

# Dependency advisories
docker compose exec php composer audit --locked --no-interaction
```

Tenant-isolation and attendance API tests live under `backend/tests/` and use SQLite tenant files under `backend/var/` (gitignored).

## Frontend

```sh
docker compose exec frontend npm test
docker compose exec frontend npm run lint
docker compose exec frontend npx tsc -b --pretty false
docker compose exec frontend npm run build
docker compose exec frontend npm audit --audit-level=high
```

## Docker validation (as in CI)

```sh
# Synthetic env only — not real secrets
export APP_SECRET=ci-placeholder-app-secret-value-32c APP_ENV=prod APP_DEBUG=0 \
  DEFAULT_URI=https://ci.example PLATFORM_HOSTNAME=ci.example \
  MYSQL_ROOT_PASSWORD=ci-root-password MYSQL_DATABASE=platform MYSQL_USER=ci_user MYSQL_PASSWORD=ci-mysql-password \
  REDIS_PASSWORD=ci-redis-password RABBITMQ_USER=ci_rabbit RABBITMQ_PASSWORD=ci-rabbit-password \
  TENANT_DB_CREDENTIALS_KEY=Y2ktcGxhY2Vob2xkZXItdGVuYW50LWtleS0zMmJ5dGVzIQ== TENANT_DB_CREDENTIALS_KEY_ID=ci

docker compose -f docker-compose.prod.yml config --quiet
docker build -f docker/php/Dockerfile.prod -t attendance-php:ci .
docker build -f docker/nginx/Dockerfile -t attendance-nginx:ci .
```

## Service health (running stack)

```sh
curl -sS http://localhost:8080/api/v1/health
curl -sS http://localhost:8080/api/v1/health/ready
docker compose exec php php bin/console app:diagnostics
```

## Not in repository

| Check | Status |
| --- | --- |
| Browser E2E (Playwright/Cypress) | Not configured |
| Load / physical terminal certification | Not configured |
| PHP CS Fixer / Pint | Not configured |

# Attendance

Multi-tenant employee attendance SaaS. The repository contains the Symfony API, the React client, Docker services, and one MySQL database per tenant.

Web sign-in, tenant configuration, the employee directory, attendance state machine, UTC time model, terminal kiosk, quotas, and the platform database explorer are implemented. A production fingerprint vendor is not connected. Each tenant has its own branding, languages, timezone, photo policy, and attendance data.

## Architecture

| Layer | Technology |
| --- | --- |
| API | Symfony 7.4, PHP 8.4, Doctrine |
| UI | React, Vite, Redux Toolkit, React Router, i18next |
| Data | MySQL (platform + per-tenant), Redis (sessions/cache), RabbitMQ (Messenger) |
| Edge | Nginx → PHP-FPM and the frontend |
| Runtime | Docker Compose (development and production layouts) |

Platform operators manage tenants on the platform host. Tenant users work on their tenant hostname. The server resolves the tenant from `Host`; the browser never supplies tenant credentials or a trusted `tenant_id`.

## Quick start

```sh
cp .env.example .env
docker compose up --build
```

Open http://localhost:8080. Liveness: http://localhost:8080/api/v1/health.

Full local setup (databases, tests, env vars): [docs/development-setup.md](docs/development-setup.md).

## Documentation

| Document | Contents |
| --- | --- |
| [docs/development-setup.md](docs/development-setup.md) | Local setup, env vars, tests, CI/CD overview |
| [docs/README.md](docs/README.md) | Developer map of the product |
| [docs/architecture.md](docs/architecture.md) | Design decisions |
| [docs/tenant-databases.md](docs/tenant-databases.md) | Provisioning and isolation |
| [docs/testing.md](docs/testing.md) | Automated test strategy |
| [docs/deployment.md](docs/deployment.md) | Staging, production, backups, rollback |

## Tests

```sh
docker compose exec php php vendor/bin/phpunit
docker compose exec php php vendor/bin/phpstan analyse --memory-limit=512M
docker compose exec frontend npm test
docker compose exec frontend npm run lint
docker compose exec frontend npx tsc -b --pretty false
```

Backend tests use SQLite and do not require Redis, RabbitMQ, or live tenant MySQL databases.

## Environment configuration

- **Local:** copy `.env.example` → `.env` (Compose). Do not commit `.env`.
- **Staging / production:** copy `.env.prod.example` → a private file (for example `.env.prod`). Fill secrets from a secret store. Never commit that file.
- Optional Database Explorer URLs must use separate read-only accounts. Leave them empty until those accounts exist.

## CI/CD overview

GitHub Actions workflows (see [docs/ci.md](docs/ci.md)):

1. **CI** (`.github/workflows/ci.yml`): pull request / push to `main` or `develop`, and `v*` tags — PHPUnit, PHPStan, Composer validate and audit, Vitest, ESLint, TypeScript, frontend build, Compose validation, Docker image builds (no push).
2. **Release images** (`.github/workflows/release-images.yml`): after CI succeeds on a push to `main` or a `v*` tag, publish `attendance-php` and `attendance-nginx` to GHCR with immutable `sha-*` tags. Pull requests never publish.
3. **Deploy** (`.github/workflows/deploy.yml`): SSH + Compose release on staging/production — **inactive** until hosting variables are set (`DEPLOY_ENABLED=true`). See [docs/ci-cd-deployment.md](docs/ci-cd-deployment.md).
4. Rollback: previous immutable image via `docker/deploy/rollback.sh`; no destructive down-migrations on tenant data.

See [docs/deployment.md](docs/deployment.md), [docs/ci-cd-deployment.md](docs/ci-cd-deployment.md), and [docs/development-setup.md](docs/development-setup.md).

## Security notes for contributors

Do not commit secrets, TLS private keys, database dumps, attendance photographs, or biometric material. `.gitignore` and `.dockerignore` exclude those paths. If a secret is found in Git history, rotate it and stop before publishing the repository.

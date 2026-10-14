# Continuous integration

GitHub Actions workflow: `.github/workflows/ci.yml`.

## Triggers

- Pull requests to `main` or `develop`
- Pushes to `main` or `develop`
- Manual `workflow_dispatch`

There is no deployment job in this workflow. Fork pull requests receive only `contents: read` and no repository secrets.

## Jobs

| Job | What it runs |
| --- | --- |
| Backend | `composer validate`, `composer install` from `composer.lock`, `php -l`, `composer audit --locked`, PHPUnit (SQLite), test cache warmup, PHPStan level 6 |
| Frontend | `npm ci`, `npm run lint`, `npm test`, `npx tsc -b`, `npm run build`, `npm audit --audit-level=high` |
| Docker | `docker compose -f docker-compose.prod.yml config` with synthetic env, then build `Dockerfile.prod` and the Nginx image (no push) |

## Services

Backend CI does **not** start MySQL, Redis, or RabbitMQ. `backend/phpunit.dist.xml` forces SQLite, `REDIS_URL=disabled://`, and `MESSENGER_TRANSPORT_DSN=in-memory://`. Tenant isolation and attendance tests in that suite use SQLite tenant databases.

Docker builds use the repository Dockerfiles only. They do not migrate or open production databases.

## Environment

No GitHub secrets are required for CI. The Docker Compose validation step sets synthetic placeholders in the job environment for required Compose variables.

## Branch protection (manual)

After the repository is on GitHub, mark these checks required on `main`:

- Backend
- Frontend
- Docker

## Missing project scripts (not invented in CI)

These are not defined in `composer.json` / `package.json` today:

| Check | Status |
| --- | --- |
| PHP CS Fixer / Pint / phpcs | Not configured |
| Named `composer audit` script | CLI is used directly in CI |
| Named `npm audit` script | CLI is used directly in CI |
| Named `typecheck` script | Covered by `tsc -b` and `npm run build` |

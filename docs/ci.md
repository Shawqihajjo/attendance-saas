# Continuous integration and image release

GitHub Actions workflows:

| Workflow | File | Purpose |
| --- | --- | --- |
| CI | `.github/workflows/ci.yml` | Lint, test, and validate production Docker builds (no push) |
| Release images | `.github/workflows/release-images.yml` | Publish production images to GHCR after CI succeeds |
| Deploy | `.github/workflows/deploy.yml` | Staging/production CD over SSH (disabled until host vars exist) |

Continuous deployment details, required secret **names**, migration order, and rollback: [ci-cd-deployment.md](ci-cd-deployment.md).

## CI triggers

- Pull requests to `main` or `develop`
- Pushes to `main` or `develop`
- Pushes of tags matching `v*`
- Manual `workflow_dispatch`

There is no deployment job in CI. Fork pull requests receive only `contents: read` and no repository secrets.

## CI jobs

| Job | What it runs |
| --- | --- |
| Backend | `composer validate`, `composer install` from `composer.lock`, `php -l`, `composer audit --locked`, PHPUnit (SQLite), test cache warmup, PHPStan level 6 |
| Frontend | `npm ci`, `npm run lint`, `npm test`, `npx tsc -b`, `npm run build`, `npm audit --audit-level=high` |
| Docker | `docker compose -f docker-compose.prod.yml config` with synthetic env, then build `docker/php/Dockerfile.prod` and `docker/nginx/Dockerfile` (no push) |

## Services

Backend CI does **not** start MySQL, Redis, or RabbitMQ. `backend/phpunit.dist.xml` forces SQLite, `REDIS_URL=disabled://`, and `MESSENGER_TRANSPORT_DSN=in-memory://`. Tenant isolation and attendance tests in that suite use SQLite tenant databases.

Docker builds use the repository production Dockerfiles only. They do not migrate or open production databases.

## Environment

No GitHub secrets are required for CI. The Docker Compose validation step sets synthetic placeholders in the job environment for required Compose variables. Image publish uses the built-in `GITHUB_TOKEN` (no personal access token).

## Production images (what is published)

Only these Dockerfiles are treated as production-ready:

| Image | Dockerfile | Role |
| --- | --- | --- |
| `attendance-php` | `docker/php/Dockerfile.prod` | Symfony API PHP-FPM (`composer install --no-dev`) |
| `attendance-nginx` | `docker/nginx/Dockerfile` | Nginx + production React build from `frontend/` |

`docker/php/Dockerfile` is a **local development** image (dev PHP config / entrypoint). It is **not** published by the release workflow.

MySQL, Redis, and RabbitMQ use upstream official images referenced in `docker-compose.prod.yml`. This repository does not define custom Dockerfiles for them, so they are not built or pushed here.

## Image naming and versioning (GHCR)

Registry: `ghcr.io`

Package names (lowercase owner required by GHCR):

- `ghcr.io/<github-owner>/attendance-php`
- `ghcr.io/<github-owner>/attendance-nginx`

Every published image is tagged with **immutable commit identifiers**:

| Tag | When |
| --- | --- |
| `sha-<12-char-short-sha>` | Always |
| `sha-<full-git-sha>` | Always |
| `main` | Push (or dispatch) of branch `main` after CI success |
| `vX.Y.Z` | Push (or dispatch) of release tag `vX.Y.Z` after CI success |
| `vX.Y` | Also applied when the tag matches `vMAJOR.MINOR.PATCH` |

OCI labels set on each image include `org.opencontainers.image.revision` (full commit SHA) and `org.opencontainers.image.source`.

The release job writes the same tags and commit into the GitHub Actions job summary.

Example for owner `Shawqihajjo` and commit `abc1234…`:

```text
ghcr.io/shawqihajjo/attendance-php:sha-abc1234def56
ghcr.io/shawqihajjo/attendance-php:sha-abc1234def567890…
ghcr.io/shawqihajjo/attendance-php:main
ghcr.io/shawqihajjo/attendance-nginx:sha-abc1234def56
…
```

## Who may publish

The release workflow runs on `workflow_run` after **CI** completes successfully, and only when:

- The triggering CI event was a **`push`** (never a pull request)
- The run belongs to **this repository** (not a fork workflow copy)
- The branch is **`main`**, or the ref is a tag starting with **`v`**

`workflow_dispatch` on the release workflow may rebuild the same approved refs, but only if a successful CI **push** run already exists for that commit, and only for `main` or `v*` tags.

**Pull requests never publish images**, including trusted-branch PRs. CI still builds images locally on the runner to validate Dockerfiles.

`packages: write` is granted only to the publish job. The workflow uses `secrets.GITHUB_TOKEN` with `docker/login-action` against `ghcr.io` — no manually stored PAT is required for GHCR from Actions.

## Secrets and sensitive layers

- `.dockerignore` excludes `.env*`, TLS material, `backend/var`, vendor/node_modules, SQL dumps, and photo/upload paths.
- `Dockerfile.prod` removes any leftover `.env*` / test tooling after `COPY`.
- Runtime secrets (`APP_SECRET`, DB passwords, `TENANT_DB_CREDENTIALS_KEY`, etc.) must be supplied by the orchestrator at deploy time (Compose/env files on the host). They are **not** baked into images and are **not** stored in this workflow.

## Image scanning (proposal — not enabled yet)

Trivy (or GitHub Advanced Security container scanning) is **not** wired into `release-images.yml` yet, to avoid unpinned third-party action drift and GHCR rate limits on free runners without a documented pin.

Recommended follow-up (explicit proposal):

1. After each successful `build-push`, run `aquasecurity/trivy-action` pinned to a full commit SHA.
2. Scan both `attendance-php` and `attendance-nginx` with `severity: CRITICAL,HIGH` and `exit-code: 1` on release tags (`v*`), `exit-code: 0` (report-only) on `main`.
3. Optionally upload SARIF to the Security tab when `security-events: write` is acceptable for that job.
4. Document the chosen Trivy version SHA in this file when added.

Until then, rely on `composer audit` / `npm audit` in CI and review GHCR package digests after publish.

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

## Local validation vs GitHub-side checks

You can validate Compose and Docker builds locally:

```bash
docker compose -f docker-compose.prod.yml config --quiet
docker build -f docker/php/Dockerfile.prod -t attendance-php:local .
docker build -f docker/nginx/Dockerfile -t attendance-nginx:local .
```

The following still require a **push to GitHub** (or Actions UI) to fully verify:

- `workflow_run` gating after CI
- GHCR login with `GITHUB_TOKEN` and package visibility
- Permission of `packages: write` only on the publish job
- Tag / branch filters and job summary recording

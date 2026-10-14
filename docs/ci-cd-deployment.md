# CI/CD and continuous deployment

This document is the operator guide for automated checks, image publishing, and (when configured) staging/production deploys for the attendance SaaS.

**Live deployment status:** No hosting target (SSH host, cloud account, IP, or managed Kubernetes cluster) is defined in this repository. Continuous deployment jobs are therefore **inactive** until you set the variables and secrets below and flip `DEPLOY_ENABLED=true`. No live deployment was tested as part of adding these workflows.

Application runtime documentation remains in [deployment.md](deployment.md). Image naming remains in [ci.md](ci.md).

---

## Pipeline overview

```text
PR / push / v* tag
        │
        ▼
   CI (ci.yml)  ─── tests, audits, Compose validate, local image builds (no push)
        │
        │  success on push to main or v* tag
        ▼
Release images (release-images.yml)  ─── publish attendance-php + attendance-nginx to GHCR
        │
        │  success on main (optional auto) or manual dispatch
        ▼
   Deploy (deploy.yml)  ─── DISABLED until DEPLOY_ENABLED + host vars
        │
        ├── staging environment  (Compose host via SSH)
        └── production environment (Compose host via SSH, required reviewers)
```

| Workflow | File | When it runs | What it does |
| --- | --- | --- | --- |
| CI | `.github/workflows/ci.yml` | PR / push to `main`\|`develop`, `v*` tags, manual | Backend, frontend, Docker validate/build. **Never deploys.** |
| Release images | `.github/workflows/release-images.yml` | After CI succeeds on push to `main` or `v*`, or manual | Push immutable images to GHCR. **Never deploys to a server.** |
| Deploy | `.github/workflows/deploy.yml` | Manual, or after Release images on `main` (staging only) | SSH + `docker/deploy/release.sh` on a configured host. **Inactive until configured.** |

Pull requests never publish images and never deploy.

---

## Deployment target (current state)

| Item | Status in repo |
| --- | --- |
| Documented runtime model | Yes — Docker Compose via `docker-compose.prod.yml` |
| Release / migrate script | Yes — `docker/deploy/release.sh` |
| Rollback script | Yes — `docker/deploy/rollback.sh` |
| Backup script | Yes — `docker/mysql/backup-databases.sh` |
| Health endpoints | Yes — `/api/v1/health`, `/api/v1/health/ready`, `app:diagnostics` |
| Staging hostname / IP | **Not specified** |
| Production hostname / IP | **Not specified** |
| Cloud provider / region | **Not specified** |
| SSH bastion / deploy user | **Not specified** |
| TLS certificates location on host | Documented pattern only (`TLS_CERT_DIR`) |

Until a real host exists, use GHCR image publishing as the release artifact, and run `release.sh` manually on a host you control.

---

## Required GitHub configuration (names only)

Set these in the repository (or in GitHub Environments `staging` / `production`). **Do not put values in workflow YAML or commit them.**

### Repository variables

| Name | Purpose |
| --- | --- |
| `DEPLOY_ENABLED` | Must be the string `true` to allow staging/production jobs to run |
| `GHCR_OWNER` | Lowercase GHCR namespace (defaults to repository owner if unset at runtime) |
| `DEPLOY_STAGING_HOST` | Staging SSH hostname or IP |
| `DEPLOY_STAGING_USER` | Staging SSH user |
| `DEPLOY_STAGING_PORT` | Optional; defaults to `22` inside the script when empty |
| `DEPLOY_STAGING_PATH` | Absolute path to the git checkout on the staging host |
| `DEPLOY_STAGING_ENV_FILE` | Optional; defaults to `.env.staging` |
| `DEPLOY_STAGING_HEALTHCHECK_URL` | Base URL for probes (example `http://127.0.0.1` on the host) |
| `DEPLOY_PRODUCTION_HOST` | Production SSH hostname or IP |
| `DEPLOY_PRODUCTION_USER` | Production SSH user |
| `DEPLOY_PRODUCTION_PORT` | Optional SSH port |
| `DEPLOY_PRODUCTION_PATH` | Absolute path to the git checkout on the production host |
| `DEPLOY_PRODUCTION_ENV_FILE` | Optional; defaults to `.env.prod` |
| `DEPLOY_PRODUCTION_HEALTHCHECK_URL` | Base URL for production probes |

### Secrets

Prefer **environment secrets** so production credentials are not available to staging jobs.

| Name | Scope | Purpose |
| --- | --- | --- |
| `DEPLOY_STAGING_SSH_KEY` | `staging` environment (or repo) | Private key for staging SSH (least privilege; deploy user only) |
| `DEPLOY_PRODUCTION_SSH_KEY` | `production` environment | Private key for production SSH |
| `DEPLOY_GHCR_READ_TOKEN` | repo or both environments | Token with `read:packages` so the **host** can `docker login ghcr.io` (not an app env file) |

Application runtime secrets (`APP_SECRET`, MySQL, Redis, RabbitMQ, `TENANT_DB_CREDENTIALS_KEY`, TLS keys) stay on the **host** in a private env file (`.env.staging` / `.env.prod`) created from `.env.prod.example`. They are not duplicated into GitHub Actions unless you later choose a different secret-distribution model.

### GitHub Environments

Create environments named exactly:

- `staging`
- `production`

For `production`, enable **required reviewers** (and optional wait timer). Production deploys only run from `workflow_dispatch` with `target=production`, so environment protection is the approval gate.

---

## Host prerequisites

On each Compose host (staging and production separately):

1. Docker Engine + Compose v2 (`docker compose` with `up --wait`).
2. A git checkout of this repository at `DEPLOY_*_PATH` (for Compose files and `docker/deploy/*.sh`).
3. A private env file (for example `.env.staging` / `.env.prod`) filled from `.env.prod.example` and a secret store. Never commit it.
4. Distinct `COMPOSE_PROJECT_NAME` values if both stacks share a machine.
5. TLS material under `TLS_CERT_DIR` when `TLS_TERMINATION=nginx`.
6. Network access to `ghcr.io` for image pulls.
7. SSH access for the deploy user with permission to run Docker and the release scripts.
8. Disk space for MySQL/Redis volumes, `media_data`, and the `backups` volume.

Manual smoke test on the host (no GitHub required):

```sh
COMPOSE_PROJECT_NAME=attendance-staging \
  ENV_FILE=.env.staging \
  DEPLOY_MODE=pull \
  IMAGE_TAG=sha-<12-char-sha> \
  GHCR_OWNER=<lowercase-owner> \
  BACKUP_BEFORE_MIGRATE=1 \
  docker/deploy/release.sh
```

---

## Staging plan

1. Push to `main` (or cut a `v*` tag) so **CI** runs.
2. On CI success, **Release images** publishes `attendance-php` and `attendance-nginx` with `sha-<commit>` tags (and `v*` tags when applicable).
3. When `DEPLOY_ENABLED=true` and staging host vars/secrets exist:
   - **Automatic:** successful Release images for `main` can trigger staging deploy of `sha-<12>`.
   - **Manual:** Actions → Deploy → `target=staging`, `image_tag=sha-…` or `v…`.
4. Remote steps (`release.sh`):
   - Pull pinned images (never `latest`).
   - Wait for MySQL/Redis/RabbitMQ healthy.
   - Optional DB backup (`BACKUP_BEFORE_MIGRATE`).
   - Stop messenger.
   - Platform migrations, then `app:tenant:migrate --all`.
   - Start stack; `app:diagnostics`; HTTP liveness, readiness, and frontend probe.
5. Job summary / logs report success or fail the workflow. Overlapping staging deploys are serialized by concurrency.

---

## Production plan

1. Prefer a **release tag** (`vX.Y.Z`) that already passed CI and has GHCR images, or an explicit `sha-*` from `main` after staging verification.
2. Actions → Deploy → `target=production`, set `image_tag`, keep backup enabled.
3. GitHub Environment **production** requires human approval before the job runs.
4. Same remote release path as staging, against production host vars/secrets and `.env.prod`.
5. Production never runs from pull requests and never from the automatic `workflow_run` path (staging-only auto path).

Concurrency group `deploy-production-<repo>` prevents overlapping production releases.

---

## Image and artifact strategy

| Artifact | Source | Traceability |
| --- | --- | --- |
| `ghcr.io/<owner>/attendance-php:<tag>` | `docker/php/Dockerfile.prod` | `sha-<git>` and OCI `revision` label |
| `ghcr.io/<owner>/attendance-nginx:<tag>` | `docker/nginx/Dockerfile` | same |

Deploy accepts only `sha-*` or `v*` tags. Mutable tags `latest` and `main` are rejected by the deploy scripts.

Compose overrides:

- `ATTENDANCE_PHP_IMAGE`
- `ATTENDANCE_NGINX_IMAGE`

---

## Database migration strategy (platform + tenants)

Migrations are **not** run on every container start. They run only inside `docker/deploy/release.sh` (or an operator-invoked console command).

Ordered steps:

1. **Recoverability:** With `BACKUP_BEFORE_MIGRATE=1`, run `backup-databases.sh` inside MySQL (platform DB + every tenant DB with `dropped_at IS NULL`). Keep `TENANT_DB_CREDENTIALS_KEY` outside the dump.
2. **Stop messenger** so domain events are not consumed mid-migration.
3. **Platform:** `doctrine:migrations:migrate --no-interaction --env=prod` on `PLATFORM_DATABASE_URL`.
4. **Tenants:** `app:tenant:migrate --all` — one tenant at a time via `MigrateTenantDatabases`. A failure throws, the command exits non-zero, and `release.sh` aborts. Earlier tenants that already migrated stay migrated; fix forward and re-run `--all`. Do not declare overall success if any tenant fails.
5. **Isolation:** Each tenant migration uses `switchForMaintenance`, then resets the tenant connection (same rules as HTTP/worker reset). No cross-tenant connection reuse.
6. **Start** PHP, Nginx, messenger; require `app:diagnostics` → `ok`.

Do **not** run these commands against production until the host, env file, and GitHub Environment approval are in place.

Recovery after a failed tenant migration:

1. Leave traffic on the previous app images if the new ones never passed health checks; if partially started, roll app images back with `docker/deploy/rollback.sh`.
2. Inspect the failing tenant (`app:tenant:check --tenant=slug`).
3. Ship a **forward** migration fix; re-run `app:tenant:migrate --all`.
4. Never Doctrine **down**-migrate production tenant data.

Full backup/restore narrative: [deployment.md](deployment.md).

---

## Health checks

| Check | How |
| --- | --- |
| Compose service health | MySQL `mysqladmin ping`; Redis `PONG`; RabbitMQ ping; PHP `app:diagnostics`; Nginx wget liveness |
| Liveness | `GET /api/v1/health` |
| Readiness | `GET /api/v1/health/ready` (platform DB, Redis, RabbitMQ; tenant connection must be unselected) |
| Diagnostics CLI | `php bin/console app:diagnostics` → JSON `status: ok` |
| Frontend | HTTP `200`/`301`/`302` on `/` through Nginx |

Port 80 keeps health paths available when `TLS_TERMINATION=nginx` (HTTPS redirect elsewhere).

---

## Rollback procedure

1. Identify the previous known-good image tag (`sha-…` or `v…`).
2. Stop messenger first (script does this).
3. On the host:

```sh
COMPOSE_PROJECT_NAME=attendance-prod \
  ENV_FILE=.env.prod \
  IMAGE_TAG=sha-<previous> \
  GHCR_OWNER=<lowercase-owner> \
  docker/deploy/rollback.sh
```

4. Confirm `app:diagnostics` and HTTP probes.
5. **Do not** roll the schema backward. If the bad release required a migration, ship a forward fix.

---

## Secrets and permissions (Actions)

| Job | Permissions |
| --- | --- |
| CI | `contents: read` |
| Release images | `contents: read`, `packages: write`, `actions: write` (cache) on publish job only |
| Deploy | `contents: read`, `packages: read` |

SSH private keys and GHCR pull tokens are Actions secrets. Workflows must not echo them. Application DB passwords never belong in image layers (see `.dockerignore` and [ci.md](ci.md)).

---

## Concrete enablement checklist

When you have a real staging/production host:

1. Provision VMs (or similar) and install Docker Compose v2.
2. Clone this repo to `DEPLOY_*_PATH`; create `.env.staging` / `.env.prod` from `.env.prod.example`.
3. Create GitHub Environments `staging` and `production` (reviewers on production).
4. Add the variables and secrets listed above (names only — paste values only in the GitHub UI).
5. Set `DEPLOY_ENABLED=true`.
6. Publish images via CI → Release images.
7. Run Deploy manually to staging with a `sha-*` tag; verify health.
8. After staging sign-off, Deploy to production with the same tag and environment approval.
9. Schedule `backup-databases.sh` daily on the host.

---

## Unimplemented / blocked by missing hosting information

| Step | Why blocked |
| --- | --- |
| Actual SSH deploy execution | No `DEPLOY_*_HOST` (or any documented server) in the repo |
| DNS / TLS certificate issuance | No production domain beyond placeholder `attendance.example` |
| Load balancer / `TLS_TERMINATION=edge` wiring | No edge provider documented |
| Cloud-specific IAM roles | No AWS/GCP/Azure/Hetzner project referenced |
| Secrets Manager sync into host env files | Host file from `.env.prod.example` is the documented model |
| Automated production deploy on tag push | Intentionally omitted; production requires explicit dispatch + environment approval |
| End-to-end live deploy test | Not performed — no target |

---

## Related files

| Path | Role |
| --- | --- |
| `.github/workflows/ci.yml` | CI |
| `.github/workflows/release-images.yml` | GHCR publish |
| `.github/workflows/deploy.yml` | Staging/production CD (gated) |
| `docker/deploy/release.sh` | Host-side release + migrations + health |
| `docker/deploy/rollback.sh` | Host-side image rollback |
| `docker/deploy/ci-ssh-deploy.sh` | Actions → SSH bridge |
| `docker-compose.prod.yml` | Production Compose (image overrides supported) |
| `.env.prod.example` | Runtime secret checklist |
| `docs/deployment.md` | Runtime ops |
| `docs/ci.md` | Image naming and publish policy |

# Audit: GitHub, CI/CD and release security

**Date:** 2026-10-14  
**Repository (local):** `/Users/shawqi/attendnce_employee`  
**GitHub remote:** `https://github.com/Shawqihajjo/attendance-saas`  
**Auditor scope:** GitHub configuration, workflows, secrets handling, image publish/deploy design. No production infrastructure or database data was modified. No deployment changes were made during this audit.

## Status legend

| Label | Meaning |
| --- | --- |
| **Verified working** | Observed to succeed in this audit (local command and/or GitHub Actions API) |
| **Failing** | Observed failure, or requirement not met |
| **Partially configured** | Design present locally and/or partially on GitHub, not fully active |
| **Not configured** | Required setting or infrastructure absent |
| **Not tested** | Could not be observed (missing auth, host, secrets, or local toolchain) |

---

## Executive summary

| # | Check | Result |
| --- | --- | --- |
| 1 | Repository private as intended | **Failing** — remote is **public** |
| 2 | No sensitive env / production data tracked | **Verified working** (with noted intentional `.env.test`) |
| 3 | GitHub Actions workflows valid | **Partially configured** — local YAML valid; only `ci.yml` on GitHub |
| 4 | Backend checks execute correctly | **Verified working** on GitHub Actions (local PHP unavailable) |
| 5 | Frontend checks execute correctly | **Verified working** on GitHub Actions (local Node unavailable) |
| 6 | Integration tests use isolated test services | **Verified working** (SQLite / disabled Redis / in-memory Messenger) |
| 7 | Docker images build successfully | **Verified working** (local + GitHub CI) |
| 8 | Container publishing only on approved triggers | **Partially configured** — workflow designed, **not on remote**, never observed to publish |
| 9 | Untrusted PRs cannot access deploy secrets | **Partially configured** — CI has no deploy secrets; Deploy not on remote / inactive |
| 10 | Workflow token least privilege | **Partially configured** — sound in local YAML; Deploy/Release not live on GitHub |
| 11 | Required checks cannot be bypassed by failed test | **Partially configured** — CI fails on failed job; **branch protection not verified** |
| 12 | Staging gated by successful CI | **Partially configured** — designed via Release→Deploy; not live; manual Deploy does not re-check CI |
| 13 | Production approval mechanism | **Partially configured** — `environment: production` in YAML; Environment settings **Not tested** |
| 14 | Deploy credentials not in source/images | **Verified working** (static + image layer spot-check) |
| 15 | Migrations platform + tenants safe design | **Partially configured** — scripts/docs present; **not run** against real DBs |
| 16 | Health checks detect failed deployments | **Partially configured** — scripted; **not tested** on a live host |
| 17 | Rollback procedures documented | **Verified working** (documentation + `rollback.sh` present) |
| 18 | Releases traceable to commit / image | **Partially configured** — tagging design present; **no GHCR publish observed** |

**Critical findings**

1. The GitHub repository is **public**, not private.
2. `release-images.yml` and `deploy.yml` exist only in the **local working tree** (untracked / unpushed). GitHub currently runs **only** `ci.yml`.
3. Branch protection and GitHub Environment reviewer settings could not be verified without authenticated `gh` / API access.
4. No staging/production host is configured; CD remains inactive by design.

---

## 1. Repository visibility

| Item | Result |
| --- | --- |
| Intended | Private (per task) |
| Observed | **public** |

**Command / evidence**

```text
curl -sS -H 'Accept: application/vnd.github+json' -H 'User-Agent: attendance-audit' \
  https://api.github.com/repos/Shawqihajjo/attendance-saas
```

**Observed fields:** `private: false`, `visibility: "public"`, `default_branch: "main"`, `html_url: https://github.com/Shawqihajjo/attendance-saas`.

**Status:** **Failing** relative to “private as intended”.  
`gh` CLI was not installed in this environment (`command not found: gh`), so settings could not be changed here (and were not requested).

---

## 2. Sensitive files and production data in Git

**Status:** **Verified working** for exclusion of production secrets and dumps.

**Tracked env-related files (intentional):**

| Path | Notes |
| --- | --- |
| `.env.example` | Local placeholders |
| `.env.prod.example` | Empty checklist; no secret values |
| `backend/.env.test` | Test-only values; SQLite; documented for CI |

**Ignore verification**

```text
git check-ignore -v .env .env.prod .env.staging backend/.env
# .gitignore matches each

git ls-files --error-unmatch backend/.env
# error: pathspec 'backend/.env' did not match any file(s) known to git
```

TLS keys under `docker/nginx/certs/` are ignored. `*.sql`, `*.sql.gz`, `backups/`, `backend/var/` are ignored.

**`.dockerignore`:** excludes `.env*`, certs, `backend/var`, uploads/photos, SQL dumps. Does **not** un-ignore `.env.test` (aligned with production image hygiene).

**Security note:** `backend/.env.test` contains a non-production `APP_SECRET` and a local tenant credentials key marked for test/dev. Acceptable for CI; must never be reused in staging/production (production boot already refuses known local patterns).

---

## 3. Workflow validity

### On GitHub (remote `main` @ `f669a5fccf3e`)

| Workflow | Path on GitHub | State |
| --- | --- | --- |
| CI | `.github/workflows/ci.yml` | **active** |
| Release images | — | **Not present on remote** |
| Deploy | — | **Not present on remote** |

```text
GET /repos/Shawqihajjo/attendance-saas/contents/.github/workflows
→ ["ci.yml"]

GET /repos/.../actions/workflows → total_count: 1 (CI)
```

### Local working tree (not fully pushed)

| File | Local status |
| --- | --- |
| `.github/workflows/ci.yml` | Modified vs `origin/main` (adds `v*` tag triggers) |
| `.github/workflows/release-images.yml` | **Untracked** |
| `.github/workflows/deploy.yml` | **Untracked** |

**Local validation**

```text
python3 -c "import yaml,glob; ..."  → YAML OK for all three
/tmp/actionlint .github/workflows/*.yml → exit 0
```

**Status:** **Partially configured** — local workflows parse and pass actionlint; GitHub only has the older `ci.yml`.

---

## 4–5. Backend and frontend checks

### Local toolchain

```text
which php composer node npm → not found
```

Local re-execution of PHPUnit / ESLint / Vitest was **Not tested** in this environment.

### GitHub Actions (observed)

| Run | Event | Conclusion | URL |
| --- | --- | --- | --- |
| 38531779410 | push `main` | **failure** (Backend PHPUnit) | https://github.com/Shawqihajjo/attendance-saas/actions/runs/38531779410 |
| 38532949548 | push `main` | **success** | https://github.com/Shawqihajjo/attendance-saas/actions/runs/38532949548 |

Successful run `38532949548` job steps (all **success**):

- **Backend:** composer validate/install, `php -l`, composer audit, prepare `.env` from `.env.test`, PHPUnit, cache warmup, PHPStan  
- **Frontend:** npm ci, ESLint, Vitest, `tsc -b`, production build, npm audit (high)  
- **Docker:** Compose config, build `Dockerfile.prod`, build nginx image  

**Status:** **Verified working** on GitHub for the commit that fixed `.env` preparation (`f669a5fccf3e`). Earlier failure confirms PHPUnit failure fails the Backend job (see §11).

---

## 6. Integration tests and isolated services

**Status:** **Verified working** (configuration review).

`backend/phpunit.dist.xml` forces:

- `PLATFORM_DATABASE_URL` / `TENANT_DATABASE_URL` → SQLite files under `var/`
- `REDIS_URL=disabled://`
- `MESSENGER_TRANSPORT_DSN=in-memory://`
- `TENANT_DATABASE_DRIVER=sqlite`

CI comments and docs state MySQL/Redis/RabbitMQ are **not** started for backend CI. No live tenant databases are targeted by the default suite.

---

## 7. Docker image builds

**Status:** **Verified working**.

**Local (this audit):**

```text
docker compose -f docker-compose.prod.yml config --quiet   # COMPOSE_OK (synthetic env)
docker build -f docker/php/Dockerfile.prod -t attendance-php:audit .   # exit 0
docker build -f docker/nginx/Dockerfile -t attendance-nginx:audit .    # exit 0

docker run --rm --entrypoint sh attendance-php:audit -c 'ls /var/www/html/.env*'
# cannot access ... No such file or directory
# tests directory absent; .env.test absent
```

**GitHub CI run 38532949548:** Docker job steps Validate Compose / Build PHP / Build Nginx → **success**. Images are **not** pushed by CI.

---

## 8. Container publishing (GHCR)

**Local design** (`.github/workflows/release-images.yml`):

- Triggers: `workflow_run` after CI, or `workflow_dispatch`
- Publishes only if CI `conclusion == success`, triggering event was **`push`**, same repository, branch `main` or `v*`
- Uses `GITHUB_TOKEN` + `packages: write` only on publish job
- Tags: `sha-<12>`, `sha-<full>`, plus `main` / `v*` as applicable
- OCI label `org.opencontainers.image.revision`

**Observed on GitHub:**

- Workflow file **not** on remote
- Packages API returned **401** without auth — no publish evidence retrieved
- No Release images workflow runs in public Actions list

**Status:** **Partially configured** / publish path **Not tested** (workflow not deployed to GitHub).

---

## 9. Untrusted pull requests and deployment secrets

| Control | Evidence | Status |
| --- | --- | --- |
| CI `permissions: contents: read` | `ci.yml` | Designed |
| CI does not reference deploy secrets | No `secrets.DEPLOY_*` in `ci.yml` | Designed |
| Deploy not triggered by `pull_request` | `deploy.yml` uses `workflow_dispatch` + `workflow_run` of Release images only | Designed |
| Fork PRs do not get environment secrets by default | GitHub platform default | Assumed; Environment config **Not tested** |
| Deploy workflow on GitHub | Absent | **Not configured** on remote |

**Status:** **Partially configured**. With only CI on GitHub today, PRs cannot invoke a Deploy workflow that does not exist remotely. Once Deploy is pushed, Environments must keep SSH keys as environment secrets (documented in `docs/ci-cd-deployment.md`).

---

## 10. Token permissions (least privilege)

| Workflow | Declared permissions |
| --- | --- |
| CI | `contents: read` (workflow default) |
| Release images | default `contents: read`; publish job adds `packages: write`, `actions: write` (GHA cache + CI lookup) |
| Deploy | `contents: read`, `packages: read` |

No workflow requests `write-all`. Release publish does not use a PAT in YAML; it uses `secrets.GITHUB_TOKEN`.

**Status:** **Partially configured** (sound locally; Release/Deploy not live on GitHub).

---

## 11. Required checks bypass

**CI behavior (Verified working on GitHub):** Failed PHPUnit failed the Backend job and the overall CI run (`38531779410`). An ordinary test failure does **not** mark CI green.

**Branch protection:**  

```text
GET /repos/.../branches/main/protection → 401 Requires authentication
```

**Status:** **Partially configured**. Without verified required status checks on `main`, a repository admin (or unprotected merge path) could still merge without green CI. **Not tested** whether required checks are enabled.

**Outstanding:** Configure branch protection requiring jobs **Backend**, **Frontend**, **Docker** (as documented in `docs/ci.md`).

---

## 12. Staging deployment gated by CI

**Design (local `deploy.yml`):**

- Auto-staging only after **Release images** succeeds on `main` (Release itself requires successful CI push).
- Manual staging via `workflow_dispatch` with immutable `image_tag`.
- Gated by `DEPLOY_ENABLED=true` and `DEPLOY_STAGING_HOST`.

**Gap:** Manual `workflow_dispatch` to staging does **not** call the GitHub API to confirm CI passed for that tag/commit (unlike Release images dispatch). An operator could request deploy of a tag that never passed CI if images somehow exist.

**Status:** **Partially configured** / **Not configured** on remote (no Deploy workflow, no host, `DEPLOY_ENABLED` unset).

---

## 13. Production approval

**Design:** Production job uses `environment: production`, only `workflow_dispatch` with `target=production`, concurrency lock, refuses PR path.

**Observed:** GitHub Environments and required reviewers **Not tested** (no auth; workflow not on remote).

**Status:** **Partially configured**. Approval mechanism exists in YAML only until Environments are created with required reviewers.

---

## 14. Deployment credentials in source or images

| Check | Result |
| --- | --- |
| No SSH keys / PATs in workflow YAML | **Verified** (references `secrets.*` names only) |
| No `.env.prod` / host passwords committed | **Verified** |
| Prod image lacks `.env*` / tests | **Verified** (docker run spot-check on `attendance-php:audit`) |
| Compose CI uses synthetic placeholders | **Verified** in `ci.yml` |

**Status:** **Verified working** for repository contents and local image layers inspected in this audit.

---

## 15. Database migrations (platform + tenants)

**Design review (not executed against live data):**

`docker/deploy/release.sh`:

1. Optional `BACKUP_BEFORE_MIGRATE=1` → `backup-databases.sh`
2. Stop messenger
3. `doctrine:migrations:migrate` (platform)
4. `app:tenant:migrate --all` (one tenant at a time; failure aborts script via `set -e`)
5. Start stack + diagnostics + HTTP probes

Application code `MigrateTenantDatabases::migrateAll` throws on first failure; prior tenants remain migrated.

**Status:** **Partially configured** (scripts present). **Not tested** against real platform/tenant databases (forbidden by audit scope).

---

## 16. Health checks

| Mechanism | Location | Tested live? |
| --- | --- | --- |
| Compose healthchecks | `docker-compose.prod.yml` | Config validated; runtime **Not tested** |
| `app:diagnostics` | `release.sh` / PHP healthcheck | **Not tested** on host |
| HTTP `/api/v1/health`, `/ready`, `/` | `release.sh` | **Not tested** on host |

**Status:** **Partially configured**. Design would fail the release script on probe failure; no live deploy observed.

---

## 17. Rollback

| Artifact | Status |
| --- | --- |
| `docs/deployment.md` rollback section | Present |
| `docs/ci-cd-deployment.md` rollback | Present |
| `docker/deploy/rollback.sh` | Present (pull previous immutable tag; no down-migrations) |

**Status:** **Verified working** as documentation/script presence. **Not tested** on a server.

---

## 18. Traceability (commit ↔ image)

**Design:** GHCR tags `sha-<12>` / `sha-<full>`; OCI `org.opencontainers.image.revision`; job summary records commit.

**Observed publishes:** none (Release workflow not on GitHub; packages API 401).

**Status:** **Partially configured** / publish **Not tested**.

---

## Workflow inventory (local)

| File | Purpose | On `origin/main`? |
| --- | --- | --- |
| `.github/workflows/ci.yml` | Lint/test/build (no push) | Yes (older revision without local `v*` tag trigger until pushed) |
| `.github/workflows/release-images.yml` | Publish to GHCR after CI | **No** |
| `.github/workflows/deploy.yml` | Staging/production SSH deploy | **No** |

Supporting scripts: `docker/deploy/release.sh`, `rollback.sh`, `ci-ssh-deploy.sh` (latter two untracked locally at audit time).

---

## Outstanding setup tasks

1. Make the GitHub repository **private** if that remains the policy.
2. Push Release images + Deploy workflows (and related docs/scripts) when ready — **only with explicit approval** (not done in this audit).
3. Enable **branch protection** on `main`: require Backend, Frontend, Docker.
4. Create GitHub Environments `staging` and `production`; set **required reviewers** on production.
5. Set repository/environment variables and secrets named in `docs/ci-cd-deployment.md` (no values in git).
6. Provision Compose hosts; set `DEPLOY_ENABLED=true` only after hosts and secrets exist.
7. Optionally add CI verification to Deploy `workflow_dispatch` (same pattern as Release images).
8. Confirm GHCR package visibility after first successful publish.
9. Install/authenticate `gh` locally if operators need to inspect protection rules and secrets.

---

## Security concerns (priority)

1. **Public repository** exposes source, CI config, and example env templates to the internet. Private was stated as intended.
2. **Unpushed CD workflows** mean production publish/deploy controls exist only on the developer machine — GitHub cannot enforce them yet.
3. **Branch protection unverified** — green CI is not proven to be mandatory for merges.
4. **Manual Deploy without CI re-check** could deploy a crafted tag if images were available.
5. **Historical note:** prior session discussion mentioned a PAT embedded in a remote URL that was scrubbed; rotate any exposed PATs if not already done (**Not re-verified** in this audit).
6. Once public + GHCR packages exist, prefer private packages or careful visibility settings for production images.

---

## Commands and results log (this audit)

| Command / API | Result |
| --- | --- |
| `GET .../repos/Shawqihajjo/attendance-saas` | `private: false`, `visibility: public` |
| `GET .../actions/workflows` | 1 workflow: CI |
| `GET .../actions/runs?per_page=5` | failure then success on `main` |
| `GET .../actions/runs/38532949548/jobs` | Backend, Frontend, Docker all success |
| `GET .../actions/runs/38531779410/jobs` | Backend PHPUnit failure |
| `GET .../branches/main/protection` | 401 |
| `GET .../packages` (user/org) | 401 |
| `GET .../contents/.github/workflows` | `ci.yml` only |
| `actionlint` + PyYAML on local workflows | exit 0 |
| `docker compose ... config` | OK |
| `docker build` PHP prod + nginx | exit 0; no `.env` in PHP image |
| Local `php` / `node` / `gh` | not available |

---

## Claims explicitly not made

- No claim that Release images or Deploy succeeded on GitHub (they were not observed).
- No claim that staging or production was deployed.
- No claim that branch protection or Environment approvals are active.
- No claim that GHCR contains attendance images.
- No claim that local PHPUnit/ESLint were re-run in this environment.

---

## Related documents

- [docs/ci.md](../ci.md)
- [docs/ci-cd-deployment.md](../ci-cd-deployment.md)
- [docs/deployment.md](../deployment.md)

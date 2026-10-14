# GitHub and CI/CD

Detailed ops: [../ci.md](../ci.md), [../ci-cd-deployment.md](../ci-cd-deployment.md). Audit snapshot: [../audits/ci-cd-audit.md](../audits/ci-cd-audit.md).

## Clone and branch

```sh
git clone https://github.com/Shawqihajjo/attendance-saas.git
cd attendance-saas
git checkout -b feature/your-topic
```

Commit and push with your normal Git workflow, then open a pull request against `main` (or `develop` if that is your team process).

**Note:** As of the CI/CD audit, the GitHub repository visibility was **public**. Confirm whether that matches your policy.

## Monitor (read-only)

Workflow: `.github/workflows/monitor.yml` — CI failure triage, weekly safe re-checks, optional HTTP probes, email reports. **No automatic remediation.** Setup: [../monitoring.md](../monitoring.md).

## What CI runs

Workflow: `.github/workflows/ci.yml`  
Triggers: pull requests and pushes to `main` / `develop`, tags `v*`, and `workflow_dispatch`.

| Job | Checks |
| --- | --- |
| Backend | composer validate, install, `php -l`, composer audit, PHPUnit, cache warmup, PHPStan |
| Frontend | npm ci, lint, vitest, `tsc -b`, production build, npm audit (high) |
| Docker | `docker-compose.prod.yml` config + build prod PHP/Nginx images (**no push**) |

Permissions: `contents: read`. Pull requests do not receive deployment secrets.

### Failed workflows

1. Open the Actions run → failed job → failed step log  
2. Reproduce locally with [testing.md](testing.md)  
3. Common historical issue: PHPUnit needing `.env` — CI copies `.env.test` → `.env`

## Container image publishing

Workflow: `.github/workflows/release-images.yml` (must be present on the default branch to run on GitHub).

- Publishes to GHCR **after CI succeeds** on push to `main` or `v*` tags  
- Never publishes from pull_request CI  
- Tags: `sha-<commit>` (immutable), plus `main` / `v*` as applicable  
- Uses `GITHUB_TOKEN` with `packages: write` only on the publish job  

If this file is not yet on the remote, publishing will not run until it is pushed.

## Staging and production deploy

Workflow: `.github/workflows/deploy.yml`

| Item | Status |
| --- | --- |
| Default | **Inactive** until `DEPLOY_ENABLED=true` and host variables exist |
| Staging | Can follow successful image release on `main`, or manual dispatch |
| Production | Manual dispatch only + GitHub Environment `production` (configure required reviewers) |
| Secrets | SSH keys and GHCR read token — names in [../ci-cd-deployment.md](../ci-cd-deployment.md) |

Migrations during deploy: platform then `app:tenant:migrate --all` inside `docker/deploy/release.sh`, with optional backup. Rollback: `docker/deploy/rollback.sh` (previous image tag; **no** schema down-migrations).

## Manual configuration still required

- Private repository policy (if desired)  
- Branch protection requiring Backend / Frontend / Docker  
- GitHub Environments and deploy host variables  
- GHCR package visibility after first publish  

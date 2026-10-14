# Read-only CI/CD and system health monitor

Workflow: `.github/workflows/monitor.yml`  
Scripts: `.github/scripts/monitor/`

This monitor **diagnoses and reports only**. It does not edit application code, migrate databases, deploy, restart services, or open fix pull requests.

**Findings:** each confirmed **FAIL** gets a unique `FINDING-…` ID in the report/email and (by default) a GitHub Issue. Fixing requires a separate approval-gated workflow — see [monitoring-remediation.md](monitoring-remediation.md).

Related: [ci.md](ci.md) · [ci-cd-deployment.md](ci-cd-deployment.md) · [monitoring-remediation.md](monitoring-remediation.md)

## What it monitors

| Area | How | Environment |
| --- | --- | --- |
| CI Backend / Frontend / Docker | Reads conclusions from the **CI** workflow via `workflow_run` | GitHub Actions API |
| Scheduled safe tests | Re-runs PHPUnit (SQLite), PHPStan, frontend lint/test/build, Compose config + image builds | Isolated Actions runners (same as CI) |
| Dependency advisories | `composer audit` / `npm audit` inside scheduled backend/frontend jobs | Isolated runners |
| Live HTTP health | Optional `GET {MONITOR_PROBE_URL}/api/v1/health` and `/api/v1/health/ready` | Read-only HTTP; **BLOCKED** if URL unset |
| Remediation | Never | N/A |

## Triggers

| Trigger | Behavior |
| --- | --- |
| CI workflow completed | Triage job results; **email on failure** (and on manual triage) |
| Cron `0 */12 * * *` (UTC) | Health probe job (email only if probe **FAIL**) |
| Cron `0 8 * * 1` (Monday 08:00 UTC) | Full safe test suite + **summary email** |
| `workflow_dispatch` | Modes: `full`, `triage`, `probe` |

Pull requests **do not** run this workflow directly, so untrusted PR code cannot access mail/probe secrets.

## Status values

| Status | Meaning |
| --- | --- |
| PASS | Check executed and succeeded |
| FAIL | Check executed and failed (confirmed) |
| PARTIAL | Incomplete or non-blocking |
| BLOCKED | Missing URL/secret/infrastructure — **not** claimed as an application outage |
| NOT TESTED | Not run in this invocation |

## Reports and history

- Markdown reports under `monitor-reports/` in the job workspace
- Uploaded as Actions **artifacts** (`monitor-ci-triage-*`, `monitor-probe-*`, `monitor-full-*`)
- Copied to the job summary
- Emails include a link to the **Monitor** workflow run

## Email configuration (names only — never commit values)

### Repository variables

| Name | Purpose |
| --- | --- |
| `MONITOR_EMAIL_TO` | Destination address |
| `MONITOR_EMAIL_FROM` | Verified sender (provider-specific) |
| `MONITOR_EMAIL_ENABLED` | Optional; default treat as enabled. Set `false` to skip sending |
| `MONITOR_PROBE_URL` | Optional staging/base URL (no trailing slash required). Empty → probes **BLOCKED** |

### Repository secrets

Choose **one** email provider:

**Option A — Resend (recommended)**

| Name | Purpose |
| --- | --- |
| `MONITOR_RESEND_API_KEY` | Resend API key |

**Option B — SMTP**

| Name | Purpose |
| --- | --- |
| `MONITOR_SMTP_HOST` | SMTP hostname |
| `MONITOR_SMTP_PORT` | Optional (default `587`) |
| `MONITOR_SMTP_USER` | SMTP username |
| `MONITOR_SMTP_PASSWORD` | SMTP password |

Optional probe auth (read-only bearer if your edge requires it):

| Name | Purpose |
| --- | --- |
| `MONITOR_PROBE_TOKEN` | Optional `Authorization: Bearer` value for probes |

If no provider is configured, email status is **BLOCKED** (recorded in logs); diagnostics and artifacts still run.

### Example GitHub UI steps

1. Repo → Settings → Secrets and variables → Actions  
2. Add variables `MONITOR_EMAIL_TO`, `MONITOR_EMAIL_FROM`  
3. Add secret `MONITOR_RESEND_API_KEY` (or SMTP secrets)  
4. Optionally set `MONITOR_PROBE_URL` to a non-production base URL  

## Permissions

Workflow permissions:

- `contents: read`
- `actions: read`
- `checks: read`

No `packages: write`, no deploy SSH keys, no database credentials.

## Security / redaction

`.github/scripts/monitor/redact.sh` strips URL passwords, bearer tokens, common secret patterns, and private key blocks before notes are embedded in reports.

Do not put employee PII, photographs, biometrics, or SQL result dumps into custom notes.

## Explicit non-goals

- Automatic code fixes or remediation PRs  
- Production restarts, deploys, rollbacks  
- Migrations or tenant data writes  
- Trusting email replies as approval to change the system  

## Local dry-run (safe)

```sh
chmod +x .github/scripts/monitor/*.sh
export REPORT_PATH=/tmp/monitor-sample.md REPO=Shawqihajjo/attendance-saas BRANCH=main SHA=deadbeef
export TRIGGER=local GENERATED_AT_UTC="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
export CHECK_LINES='CI workflow|PASS|info|example'$'\n''Live probe|BLOCKED|medium|MONITOR_PROBE_URL unset'
export SUMMARY_NOTE='Local dry-run only'
.github/scripts/monitor/build-report.sh
# Email (optional; requires real secrets — do not commit them):
# MONITOR_EMAIL_TO=you@example.com MONITOR_EMAIL_FROM=alerts@example.com \
#   SUBJECT='[TEST] monitor' BODY_FILE=/tmp/monitor-sample.md \
#   .github/scripts/monitor/send-email.sh
```

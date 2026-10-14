# Approval-gated remediation

Monitor stays **read-only**. Remediation is a **separate** workflow that cannot start without authenticated approval of a **specific** Finding ID.

| Piece | Path |
| --- | --- |
| Monitor (read-only) | `.github/workflows/monitor.yml` · [monitoring.md](monitoring.md) |
| Remediation | `.github/workflows/remediation.yml` |
| Approval gate script | `.github/scripts/remediation/verify-approval.sh` |
| Gate unit tests | `.github/scripts/remediation/verify-approval.test.sh` |

## Finding IDs

When Monitor detects a **FAIL** check it emits:

- `FINDING-YYYYMMDD-HHMMSS-<sha7>-<n>` JSON under `findings-out/`
- A section in the monitoring email/report with severity, evidence, proposed fix, risks, tests, and links
- A GitHub Issue (unless `MONITOR_CREATE_ISSUES=false`) titled `[Monitor] FINDING-…`

Plain email text is **never** treated as authorization.

## Dual approval gate

Remediation requires **both**:

1. **GitHub Environment `remediation`** — create it under Settings → Environments and enable **Required reviewers**. The `gate` job uses `environment: remediation`.
2. **Issue label `remediation-approved`** on an **open** issue whose title/body contains the exact Finding ID, updated within `REMEDIATION_APPROVAL_MAX_AGE_HOURS` (default **168**).

Dispatch inputs must also re-type the Finding ID as `confirm_finding_id`. Mismatch → deny.

If any gate fails, the workflow **stops**. No branch, PR, or application changes.

## How to approve a finding

1. Open the Monitor finding issue.
2. Confirm the Finding ID and scope.
3. Add label **`remediation-approved`** (you must have permission to label).
4. Actions → **Remediation** → Run workflow:
   - `finding_id` = the Finding ID  
   - `issue_number` = issue number  
   - `confirm_finding_id` = same Finding ID  
   - `approved_scope` = one-sentence authorized scope  
   - optional `finding_artifact_run_id` = Monitor run that uploaded the finding JSON  
5. Approve the Environment **remediation** prompt when GitHub asks.

## What Remediation does after approval

1. Re-runs gate unit tests + live issue verification.  
2. Creates branch `remediation/<FINDING_ID>` from the default branch.  
3. Commits an **authority package** under `docs/audits/findings/<FINDING_ID>/` (finding snapshot + `AUTHORITY.md`).  
4. Opens a **draft** pull request.  
5. Comments the audit trail on the issue.  
6. Uploads a remediation audit artifact and emails a summary (same mail secrets as Monitor).

**Application code fixes** are applied as additional commits on that branch **only within `approved_scope`**. This workflow does **not** invent product fixes by itself, merge the PR, or deploy.

If the fix needs migrations, destructive ops, or architecture beyond the approved scope → **stop** and request a new Finding / approval.

## Production

Production deploy remains the separate **Deploy** workflow / Environment `production`. Remediation never deploys.

## Configuration

| Name | Type | Purpose |
| --- | --- | --- |
| Environment `remediation` | GitHub Environment | Required reviewers (mandatory for real protection) |
| `REMEDIATION_APPROVAL_MAX_AGE_HOURS` | Variable | Optional; default 168 |
| `MONITOR_CREATE_ISSUES` | Variable | Set `false` to skip auto issue creation |
| Mail secrets | Same as [monitoring.md](monitoring.md) | Remediation summary email |

## Proving missing approval blocks remediation

```sh
chmod +x .github/scripts/remediation/*.sh
.github/scripts/remediation/verify-approval.test.sh
```

The test suite asserts non-zero exits for missing inputs, confirm mismatch, missing finding on issue, missing label, closed issue, and expired approval — and exit 0 only for a valid mock approval.

The **Remediation** workflow also runs these tests on every `workflow_dispatch` (`gate-unit-tests` job) and again inside the environment-gated `gate` job before prepare.

## Audit fields recorded

Finding ID, approver (`github.actor`), approved scope, UTC timestamp, branch, authority commit, draft PR URL, workflow run URL, remaining risks (in audit markdown). Secrets are not written to logs or mail bodies beyond provider status lines.

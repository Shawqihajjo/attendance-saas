#!/bin/sh
# Create one GitHub Issue per finding JSON.
# Env: FINDINGS_DIR, GITHUB_REPOSITORY, GH_TOKEN
# Optional: MONITOR_CREATE_ISSUES=true|false
set -eu

FINDINGS_DIR="${FINDINGS_DIR:-findings-out}"
REPO="${GITHUB_REPOSITORY:?GITHUB_REPOSITORY required}"
: > "$FINDINGS_DIR/issue-map.txt"

if [ "${MONITOR_CREATE_ISSUES:-true}" = "false" ]; then
  echo "Skipping issue creation (MONITOR_CREATE_ISSUES=false)"
  exit 0
fi

if ! command -v gh >/dev/null 2>&1; then
  echo "gh not available; cannot create finding issues" >&2
  exit 1
fi

for json in "$FINDINGS_DIR"/FINDING-*.json; do
  [ -f "$json" ] || continue
  FID="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["finding_id"])' "$json")"
  EXISTING="$(gh issue list --repo "$REPO" --state open --search "$FID in:title,body" --json number --jq '.[0].number // empty' || true)"
  if [ -n "$EXISTING" ]; then
    echo "Issue #${EXISTING} already tracks ${FID}"
    echo "${FID}=${EXISTING}" >> "$FINDINGS_DIR/issue-map.txt"
    continue
  fi

  TITLE="$(python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); print("[Monitor] %s: %s (%s)" % (d["finding_id"], d["check_name"], d["severity"]))' "$json")"
  BODY="$(FINDING_JSON="$json" python3 - <<'PY'
import json, os
d = json.load(open(os.environ["FINDING_JSON"]))
def row(k, v):
    return f"| {k} | {v} |"
parts = [
  f"## {d['finding_id']}",
  "",
  "| Field | Value |",
  "| --- | --- |",
  row("Finding ID", f"`{d['finding_id']}`"),
  row("Severity", d.get("severity", "")),
  row("Description", d.get("description", "")),
  row("Evidence", d.get("evidence", "")),
  row("Root cause / uncertainty", d.get("root_cause_or_uncertainty", "")),
  row("Proposed fix", d.get("proposed_fix", "")),
  row("Expected files/modules", d.get("expected_files_or_modules", "")),
  row("Potential risks", d.get("potential_risks", "")),
  row("Tests required", d.get("tests_required", "")),
  row("Branch", d.get("branch", "")),
  row("Commit", f"`{d.get('commit_sha', '')}`"),
  row("Source run", d.get("ci_or_source_run_url") or "n/a"),
  row("Monitor run", d.get("monitor_run_url") or "n/a"),
  "",
  "### Approval instructions",
  "",
  "1. Review evidence. Opening/reading this issue is **not** permission to change code.",
  "2. To authorize remediation for **only** this finding, add label `remediation-approved`.",
  "3. Run Actions → **Remediation** (`workflow_dispatch`) with:",
  f"   - `finding_id` = `{d['finding_id']}`",
  "   - `issue_number` = this issue number",
  f"   - `confirm_finding_id` = `{d['finding_id']}` (must match exactly)",
  "   - `approved_scope` = short description of what you authorize",
  "4. Approve the GitHub Environment **remediation** reviewer prompt (required).",
  "5. Review the draft PR; do **not** auto-merge; production deploy needs separate approval.",
  "",
  "Plain email replies are **not** authorization.",
]
print("\n".join(parts))
PY
)"

  URL="$(gh issue create --repo "$REPO" --title "$TITLE" --body "$BODY")"
  ISSUE_NUM="$(printf '%s' "$URL" | sed -n 's#.*/issues/\([0-9][0-9]*\)$#\1#p')"
  echo "Created ${FID} -> ${URL}"
  echo "${FID}=${ISSUE_NUM}" >> "$FINDINGS_DIR/issue-map.txt"
done

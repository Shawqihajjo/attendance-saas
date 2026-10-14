#!/bin/sh
# Emit finding JSON + markdown section for each FAIL check line.
# Env: CHECK_LINES, REPO, BRANCH, SHA, RUN_URL, MONITOR_RUN_URL, GENERATED_AT_UTC, FINDINGS_DIR
set -eu

FINDINGS_DIR="${FINDINGS_DIR:-findings-out}"
export FINDINGS_DIR
mkdir -p "$FINDINGS_DIR"
: > "$FINDINGS_DIR/email-section.md"
: > "$FINDINGS_DIR/ids.txt"

REPO="${REPO:-unknown}"
BRANCH="${BRANCH:-unknown}"
SHA="${SHA:-unknown}"
RUN_URL="${RUN_URL:-}"
MONITOR_RUN_URL="${MONITOR_RUN_URL:-}"
GENERATED_AT_UTC="${GENERATED_AT_UTC:-$(date -u +%Y-%m-%dT%H:%M:%SZ)}"
STAMP="$(date -u +%Y%m%d-%H%M%S)"
SHORT="$(printf '%s' "$SHA" | cut -c1-7)"
SEQ=0

TMP_LINES="$(mktemp)"
printf '%s\n' "${CHECK_LINES:-}" > "$TMP_LINES"

while IFS= read -r line; do
  [ -z "$line" ] && continue
  name=$(printf '%s' "$line" | cut -d'|' -f1)
  status=$(printf '%s' "$line" | cut -d'|' -f2)
  severity=$(printf '%s' "$line" | cut -d'|' -f3)
  evidence=$(printf '%s' "$line" | cut -d'|' -f4-)
  case "$status" in
    FAIL) ;;
    *) continue ;;
  esac
  SEQ=$((SEQ + 1))
  FID="FINDING-${STAMP}-${SHORT}-${SEQ}"
  printf '%s\n' "$FID" >> "$FINDINGS_DIR/ids.txt"

  export FID NAME_F="$name" SEV_F="$severity" EV_F="$evidence"
  export REPO BRANCH SHA RUN_URL MONITOR_RUN_URL GENERATED_AT_UTC
  python3 - <<'PY'
import json, os
fid = os.environ["FID"]
name = os.environ["NAME_F"]
sev = os.environ["SEV_F"]
evidence = os.environ["EV_F"]
doc = {
  "finding_id": fid,
  "severity": sev,
  "status": "FAIL",
  "check_name": name,
  "description": f"Confirmed failure in check: {name}",
  "evidence": evidence,
  "root_cause_or_uncertainty": "Root cause not confirmed by an automated debugger; treat evidence as a starting point.",
  "proposed_fix": (
    f"Investigate failure for '{name}'. Reproduce in an isolated CI/test environment. "
    f"Apply the minimal fix for {fid} only after explicit GitHub approval of that finding."
  ),
  "expected_files_or_modules": f"To be confirmed during remediation — area related to '{name}'.",
  "potential_risks": "Over-scoping; masking flakes; production config changes; migrations without separate approval.",
  "tests_required": "Re-run the failing suite (Backend/Frontend/Docker as applicable); add regression coverage if app logic.",
  "repository": os.environ.get("REPO", ""),
  "branch": os.environ.get("BRANCH", ""),
  "commit_sha": os.environ.get("SHA", ""),
  "ci_or_source_run_url": os.environ.get("RUN_URL", ""),
  "monitor_run_url": os.environ.get("MONITOR_RUN_URL", ""),
  "generated_at_utc": os.environ.get("GENERATED_AT_UTC", ""),
  "approval_label_required": "remediation-approved",
  "remediation_workflow": ".github/workflows/remediation.yml",
}
path = os.path.join(os.environ.get("FINDINGS_DIR", "findings-out"), f"{fid}.json")
open(path, "w", encoding="utf-8").write(json.dumps(doc, indent=2) + "\n")
PY

  {
    echo "## ${FID}"
    echo
    echo "| Field | Value |"
    echo "| --- | --- |"
    echo "| Finding ID | \`${FID}\` |"
    echo "| Severity | ${severity} |"
    echo "| Description | Failure in **${name}** |"
    echo "| Evidence | ${evidence} |"
    echo "| Root cause / uncertainty | Root cause not confirmed by an automated debugger; treat evidence as a starting point. |"
    echo "| Proposed fix | Investigate '${name}' in isolation; minimal fix only after approving \`${FID}\`. |"
    echo "| Expected files/modules | To be confirmed — related to **${name}** |"
    echo "| Potential risks | Over-scoping; masking flakes; prod config/migrations without separate approval |"
    echo "| Tests required | Re-run failing CI job suite; add regression if app logic |"
    [ -n "$RUN_URL" ] && echo "| Source run | ${RUN_URL} |"
    [ -n "$MONITOR_RUN_URL" ] && echo "| Monitor report run | ${MONITOR_RUN_URL} |"
    echo
    echo "**Approval:** Label the GitHub Issue with \`remediation-approved\`, then run workflow **Remediation** with Finding ID \`${FID}\`. Plain email replies are **not** authorization."
    echo
  } >> "$FINDINGS_DIR/email-section.md"
done < "$TMP_LINES"
rm -f "$TMP_LINES"

COUNT=0
if [ -f "$FINDINGS_DIR/ids.txt" ]; then
  COUNT="$(grep -c . "$FINDINGS_DIR/ids.txt" || true)"
fi
IDS="$(tr '\n' ',' < "$FINDINGS_DIR/ids.txt" | sed 's/,$//')"
if [ -n "${GITHUB_OUTPUT:-}" ]; then
  echo "finding_count=${COUNT}" >> "$GITHUB_OUTPUT"
  echo "finding_ids=${IDS}" >> "$GITHUB_OUTPUT"
fi
echo "Emitted ${COUNT} finding(s): ${IDS}"

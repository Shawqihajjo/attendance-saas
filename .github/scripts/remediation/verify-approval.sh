#!/bin/sh
# Verify authenticated approval for a finding before any remediation work.
#
# Required env:
#   FINDING_ID          e.g. FINDING-20261014-120000-abc1234-1
#   ISSUE_NUMBER        GitHub issue number that tracks the finding
#   GITHUB_REPOSITORY   owner/repo (or REPO)
#
# Optional:
#   APPROVAL_LABEL      default remediation-approved
#   APPROVAL_MAX_AGE_HOURS  default 168 (7 days); based on issue updated_at
#   GH_TOKEN / GITHUB_TOKEN  for gh api
#   GH_MOCK_FILE        if set, read issue JSON from this file instead of API (tests)
#
# Exit codes:
#   0 — approved
#   10 — missing/invalid inputs
#   11 — issue not found / API error
#   12 — finding ID not present on issue
#   13 — approval label missing
#   14 — approval expired / issue not open
#   15 — confirm mismatch (CONFIRM_FINDING_ID)
set -eu

FINDING_ID="${FINDING_ID:-}"
ISSUE_NUMBER="${ISSUE_NUMBER:-}"
REPO="${GITHUB_REPOSITORY:-${REPO:-}}"
APPROVAL_LABEL="${APPROVAL_LABEL:-remediation-approved}"
APPROVAL_MAX_AGE_HOURS="${APPROVAL_MAX_AGE_HOURS:-168}"
CONFIRM_FINDING_ID="${CONFIRM_FINDING_ID:-}"

log() { printf '%s\n' "$*" >&2; }

if [ -z "$FINDING_ID" ] || [ -z "$ISSUE_NUMBER" ] || [ -z "$REPO" ]; then
  log "APPROVAL_GATE=DENY reason=missing_FINDING_ID_or_ISSUE_NUMBER_or_REPO"
  exit 10
fi

case "$FINDING_ID" in
  FINDING-*) ;;
  *)
    log "APPROVAL_GATE=DENY reason=invalid_finding_id_format"
    exit 10
    ;;
esac

if [ -n "$CONFIRM_FINDING_ID" ] && [ "$CONFIRM_FINDING_ID" != "$FINDING_ID" ]; then
  log "APPROVAL_GATE=DENY reason=confirm_finding_id_mismatch"
  exit 15
fi

if [ -n "${GH_MOCK_FILE:-}" ]; then
  if [ ! -f "$GH_MOCK_FILE" ]; then
    log "APPROVAL_GATE=DENY reason=mock_file_missing"
    exit 11
  fi
  ISSUE_JSON="$(cat "$GH_MOCK_FILE")"
else
  if ! command -v gh >/dev/null 2>&1; then
    log "APPROVAL_GATE=DENY reason=gh_cli_missing"
    exit 11
  fi
  ISSUE_JSON="$(gh api "/repos/${REPO}/issues/${ISSUE_NUMBER}" 2>/dev/null || true)"
  if [ -z "$ISSUE_JSON" ]; then
    log "APPROVAL_GATE=DENY reason=issue_fetch_failed"
    exit 11
  fi
fi

STATE="$(printf '%s' "$ISSUE_JSON" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("state",""))')"
TITLE="$(printf '%s' "$ISSUE_JSON" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("title") or "")')"
BODY="$(printf '%s' "$ISSUE_JSON" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("body") or "")')"
UPDATED="$(printf '%s' "$ISSUE_JSON" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("updated_at") or "")')"
LABELS="$(printf '%s' "$ISSUE_JSON" | python3 -c 'import json,sys; print(",".join(l["name"] for l in json.load(sys.stdin).get("labels") or []))')"
USER_LOGIN="$(printf '%s' "$ISSUE_JSON" | python3 -c 'import json,sys; u=json.load(sys.stdin).get("user") or {}; print(u.get("login") or "")')"

if [ "$STATE" != "open" ]; then
  log "APPROVAL_GATE=DENY reason=issue_not_open state=${STATE}"
  exit 14
fi

case "${TITLE} ${BODY}" in
  *"${FINDING_ID}"*) ;;
  *)
    log "APPROVAL_GATE=DENY reason=finding_id_not_on_issue"
    exit 12
    ;;
esac

case ",${LABELS}," in
  *",${APPROVAL_LABEL},"*) ;;
  *)
    log "APPROVAL_GATE=DENY reason=approval_label_missing label=${APPROVAL_LABEL} labels=${LABELS}"
    exit 13
    ;;
esac

# Age check (updated_at as proxy for recent approval activity)
python3 - "$UPDATED" "$APPROVAL_MAX_AGE_HOURS" <<'PY' || exit 14
import sys
from datetime import datetime, timezone, timedelta
updated = sys.argv[1]
max_hours = int(sys.argv[2])
if not updated:
    print("APPROVAL_GATE=DENY reason=missing_updated_at", file=sys.stderr)
    sys.exit(14)
# 2024-01-01T00:00:00Z
ts = datetime.fromisoformat(updated.replace("Z", "+00:00"))
age = datetime.now(timezone.utc) - ts
if age > timedelta(hours=max_hours):
    print(f"APPROVAL_GATE=DENY reason=approval_expired age_hours={age.total_seconds()/3600:.1f}", file=sys.stderr)
    sys.exit(14)
print(f"approval_age_hours={age.total_seconds()/3600:.2f}")
PY

log "APPROVAL_GATE=ALLOW finding_id=${FINDING_ID} issue=${ISSUE_NUMBER} label=${APPROVAL_LABEL} issue_author=${USER_LOGIN}"
# Machine-readable for workflows
echo "approver_hint=${USER_LOGIN}"
echo "issue_title=${TITLE}"
echo "approved_label=${APPROVAL_LABEL}"
exit 0

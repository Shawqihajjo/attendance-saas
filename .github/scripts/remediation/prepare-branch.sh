#!/bin/sh
# After approval gate passes: create isolated branch, write audit package, open draft PR.
# Does NOT implement application business-logic fixes (those are applied on this branch
# only within the approved finding scope by a subsequent human/agent session).
#
# Env: FINDING_ID, ISSUE_NUMBER, APPROVED_SCOPE, APPROVER, GITHUB_REPOSITORY,
#      FINDING_JSON (path), BASE_REF (default main)
set -eu

FINDING_ID="${FINDING_ID:?}"
ISSUE_NUMBER="${ISSUE_NUMBER:?}"
APPROVED_SCOPE="${APPROVED_SCOPE:?}"
APPROVER="${APPROVER:-unknown}"
REPO="${GITHUB_REPOSITORY:?}"
FINDING_JSON="${FINDING_JSON:?}"
BASE_REF="${BASE_REF:-main}"
BRANCH="remediation/${FINDING_ID}"
UTC="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

git fetch origin "$BASE_REF"
git checkout -B "$BRANCH" "origin/${BASE_REF}"

AUDIT_DIR="docs/audits/findings/${FINDING_ID}"
mkdir -p "$AUDIT_DIR"
cp "$FINDING_JSON" "$AUDIT_DIR/finding.json"

{
  echo "# Remediation authority — ${FINDING_ID}"
  echo
  echo "| Field | Value |"
  echo "| --- | --- |"
  echo "| Finding ID | \`${FINDING_ID}\` |"
  echo "| Issue | #${ISSUE_NUMBER} |"
  echo "| Approver (dispatch actor / issue author hint) | \`${APPROVER}\` |"
  echo "| Approved scope | ${APPROVED_SCOPE} |"
  echo "| Approval recorded (UTC) | \`${UTC}\` |"
  echo "| Branch | \`${BRANCH}\` |"
  echo "| Base | \`${BASE_REF}\` |"
  echo
  echo "## Rules"
  echo
  echo "- Implement **only** what is required for \`${FINDING_ID}\` within the approved scope."
  echo "- Do **not** expand to unrelated refactors, migrations, or dependency upgrades unless newly approved."
  echo "- Do **not** merge this PR automatically."
  echo "- Do **not** deploy to production from this workflow."
  echo "- If the fix needs migrations, destructive ops, or broader architecture, **stop** and request new approval."
  echo
  echo "## Finding snapshot"
  echo
  echo '```json'
  cat "$FINDING_JSON"
  echo '```'
} > "$AUDIT_DIR/AUTHORITY.md"

git add "$AUDIT_DIR"
git -c user.name="attendance-monitor[bot]" -c user.email="monitor@users.noreply.github.com" \
  commit -m "$(printf '%s\n\n%s\n' "chore(monitor): authorize remediation for ${FINDING_ID}" "Issue #${ISSUE_NUMBER}. Scope: ${APPROVED_SCOPE}")"

git push -u origin "$BRANCH"

PR_URL="$(gh pr create --repo "$REPO" --base "$BASE_REF" --head "$BRANCH" --draft \
  --title "[${FINDING_ID}] Remediation (draft)" \
  --body "$(cat <<EOF
## Finding
\`${FINDING_ID}\` — tracked in #${ISSUE_NUMBER}

## Approved scope
${APPROVED_SCOPE}

## Approver
\`${APPROVER}\` at ${UTC}

## What this PR contains so far
- Audit package under \`docs/audits/findings/${FINDING_ID}/\` (authority + finding snapshot)
- **No application behavior change yet** — apply the authorized fix as additional commits on this branch only

## Required before merge
- [ ] Fix limited to approved scope
- [ ] Regression tests added/updated
- [ ] Backend/frontend checks green
- [ ] Human review
- [ ] **Do not** production-deploy from this PR without separate Deploy approval

EOF
)")"

echo "PR_URL=${PR_URL}"
if [ -n "${GITHUB_OUTPUT:-}" ]; then
  echo "branch=${BRANCH}" >> "$GITHUB_OUTPUT"
  echo "pr_url=${PR_URL}" >> "$GITHUB_OUTPUT"
  echo "audit_dir=${AUDIT_DIR}" >> "$GITHUB_OUTPUT"
fi

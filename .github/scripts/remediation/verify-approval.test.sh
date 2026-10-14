#!/bin/sh
# Prove that missing/invalid approval prevents remediation from starting.
# Runs offline with GH_MOCK_FILE — no network, no repo mutation.
set -eu

ROOT="$(CDPATH= cd -- "$(dirname "$0")/../../.." && pwd)"
VERIFY="$ROOT/.github/scripts/remediation/verify-approval.sh"
chmod +x "$VERIFY"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

pass=0
fail=0

assert_exit() {
  name="$1"
  want="$2"
  shift 2
  set +e
  "$@" >"$TMP/out.txt" 2>"$TMP/err.txt"
  got=$?
  set -e
  if [ "$got" = "$want" ]; then
    echo "PASS ${name} (exit ${got})"
    pass=$((pass + 1))
  else
    echo "FAIL ${name} want_exit=${want} got=${got}"
    echo "--- stderr ---"; cat "$TMP/err.txt"
    fail=$((fail + 1))
  fi
}

FID="FINDING-20261014-120000-deadbee-1"

# 1) Missing inputs
assert_exit missing_inputs 10 \
  env -i PATH="$PATH" FINDING_ID= ISSUE_NUMBER=1 REPO=o/r "$VERIFY"

# 2) Confirm mismatch
cat >"$TMP/issue.json" <<EOF
{"state":"open","title":"${FID} test","body":"${FID}","updated_at":"2099-01-01T00:00:00Z","labels":[{"name":"remediation-approved"}],"user":{"login":"approver"}}
EOF
assert_exit confirm_mismatch 15 \
  env -i PATH="$PATH" FINDING_ID="$FID" CONFIRM_FINDING_ID="FINDING-OTHER" ISSUE_NUMBER=1 REPO=o/r GH_MOCK_FILE="$TMP/issue.json" "$VERIFY"

# 3) Finding ID not on issue
cat >"$TMP/issue.json" <<EOF
{"state":"open","title":"unrelated","body":"no id here","updated_at":"2099-01-01T00:00:00Z","labels":[{"name":"remediation-approved"}],"user":{"login":"approver"}}
EOF
assert_exit finding_missing 12 \
  env -i PATH="$PATH" FINDING_ID="$FID" ISSUE_NUMBER=1 REPO=o/r GH_MOCK_FILE="$TMP/issue.json" "$VERIFY"

# 4) Label missing
cat >"$TMP/issue.json" <<EOF
{"state":"open","title":"${FID}","body":"Finding ${FID}","updated_at":"2099-01-01T00:00:00Z","labels":[{"name":"bug"}],"user":{"login":"approver"}}
EOF
assert_exit label_missing 13 \
  env -i PATH="$PATH" FINDING_ID="$FID" ISSUE_NUMBER=1 REPO=o/r GH_MOCK_FILE="$TMP/issue.json" "$VERIFY"

# 5) Issue closed
cat >"$TMP/issue.json" <<EOF
{"state":"closed","title":"${FID}","body":"${FID}","updated_at":"2099-01-01T00:00:00Z","labels":[{"name":"remediation-approved"}],"user":{"login":"approver"}}
EOF
assert_exit issue_closed 14 \
  env -i PATH="$PATH" FINDING_ID="$FID" ISSUE_NUMBER=1 REPO=o/r GH_MOCK_FILE="$TMP/issue.json" "$VERIFY"

# 6) Expired approval
cat >"$TMP/issue.json" <<EOF
{"state":"open","title":"${FID}","body":"${FID}","updated_at":"2020-01-01T00:00:00Z","labels":[{"name":"remediation-approved"}],"user":{"login":"approver"}}
EOF
assert_exit expired 14 \
  env -i PATH="$PATH" FINDING_ID="$FID" ISSUE_NUMBER=1 REPO=o/r APPROVAL_MAX_AGE_HOURS=24 GH_MOCK_FILE="$TMP/issue.json" "$VERIFY"

# 7) Valid approval
cat >"$TMP/issue.json" <<EOF
{"state":"open","title":"[Monitor] ${FID}","body":"Finding ID: ${FID}\nSeverity: high","updated_at":"2099-01-01T00:00:00Z","labels":[{"name":"remediation-approved"},{"name":"monitor"}],"user":{"login":"shawqi"}}
EOF
assert_exit allow 0 \
  env -i PATH="$PATH" FINDING_ID="$FID" CONFIRM_FINDING_ID="$FID" ISSUE_NUMBER=42 REPO=o/r GH_MOCK_FILE="$TMP/issue.json" "$VERIFY"

echo
echo "Results: pass=${pass} fail=${fail}"
[ "$fail" = "0" ]

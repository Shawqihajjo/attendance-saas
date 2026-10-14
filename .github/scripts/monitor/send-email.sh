#!/bin/sh
# Send a monitoring email via Resend API or SMTP.
# Never prints secret values.
#
# Required:
#   MONITOR_EMAIL_TO, MONITOR_EMAIL_FROM, SUBJECT, BODY_FILE
# Provider (one of):
#   MONITOR_RESEND_API_KEY
#   or MONITOR_SMTP_HOST + MONITOR_SMTP_USER + MONITOR_SMTP_PASSWORD
# Optional:
#   MONITOR_SMTP_PORT (default 587), MONITOR_SMTP_STARTTLS (default 1)
#   RUN_URL — appended to body if set
set -eu

if [ "${MONITOR_EMAIL_ENABLED:-true}" = "false" ]; then
  echo "EMAIL_STATUS=SKIPPED reason=MONITOR_EMAIL_ENABLED=false"
  exit 0
fi

TO="${MONITOR_EMAIL_TO:-}"
FROM="${MONITOR_EMAIL_FROM:-}"
SUBJECT="${SUBJECT:-Attendance SaaS monitoring}"
BODY_FILE="${BODY_FILE:?BODY_FILE is required}"

if [ -z "$TO" ] || [ -z "$FROM" ]; then
  echo "EMAIL_STATUS=BLOCKED reason=MONITOR_EMAIL_TO_or_FROM_unset"
  exit 0
fi

if [ ! -f "$BODY_FILE" ]; then
  echo "EMAIL_STATUS=FAIL reason=missing_body_file" >&2
  exit 1
fi

BODY="$(cat "$BODY_FILE")"
if [ -n "${RUN_URL:-}" ]; then
  BODY="${BODY}

---
Workflow run: ${RUN_URL}
"
fi

# Prefer Resend when API key is present.
if [ -n "${MONITOR_RESEND_API_KEY:-}" ]; then
  # JSON-escape via Python for safe payload.
  PAYLOAD="$(SUBJECT="$SUBJECT" FROM="$FROM" TO="$TO" BODY="$BODY" python3 - <<'PY'
import json, os
print(json.dumps({
  "from": os.environ["FROM"],
  "to": [os.environ["TO"]],
  "subject": os.environ["SUBJECT"],
  "text": os.environ["BODY"],
}))
PY
)"
  HTTP_CODE="$(curl -sS -o /tmp/monitor-email-response.json -w '%{http_code}' \
    -X POST 'https://api.resend.com/emails' \
    -H "Authorization: Bearer ${MONITOR_RESEND_API_KEY}" \
    -H 'Content-Type: application/json' \
    -d "$PAYLOAD" || true)"
  if [ "$HTTP_CODE" = "200" ] || [ "$HTTP_CODE" = "201" ]; then
    echo "EMAIL_STATUS=PASS provider=resend http=${HTTP_CODE}"
    exit 0
  fi
  echo "EMAIL_STATUS=FAIL provider=resend http=${HTTP_CODE}" >&2
  # Do not dump response body (may contain provider details); keep exit non-zero for caller.
  exit 1
fi

if [ -n "${MONITOR_SMTP_HOST:-}" ]; then
  PORT="${MONITOR_SMTP_PORT:-587}"
  if ! command -v python3 >/dev/null 2>&1; then
    echo "EMAIL_STATUS=BLOCKED reason=python3_missing_for_smtp" >&2
    exit 0
  fi
  SUBJECT="$SUBJECT" FROM="$FROM" TO="$TO" BODY="$BODY" \
  MONITOR_SMTP_HOST="$MONITOR_SMTP_HOST" MONITOR_SMTP_PORT="$PORT" \
  MONITOR_SMTP_USER="${MONITOR_SMTP_USER:-}" MONITOR_SMTP_PASSWORD="${MONITOR_SMTP_PASSWORD:-}" \
  MONITOR_SMTP_STARTTLS="${MONITOR_SMTP_STARTTLS:-1}" \
  python3 - <<'PY'
import os, smtplib, ssl, sys
from email.message import EmailMessage

host = os.environ["MONITOR_SMTP_HOST"]
port = int(os.environ["MONITOR_SMTP_PORT"])
user = os.environ.get("MONITOR_SMTP_USER") or ""
password = os.environ.get("MONITOR_SMTP_PASSWORD") or ""
starttls = os.environ.get("MONITOR_SMTP_STARTTLS", "1") == "1"

msg = EmailMessage()
msg["Subject"] = os.environ["SUBJECT"]
msg["From"] = os.environ["FROM"]
msg["To"] = os.environ["TO"]
msg.set_content(os.environ["BODY"])

try:
    if starttls:
        with smtplib.SMTP(host, port, timeout=60) as smtp:
            smtp.ehlo()
            smtp.starttls(context=ssl.create_default_context())
            smtp.ehlo()
            if user:
                smtp.login(user, password)
            smtp.send_message(msg)
    else:
        with smtplib.SMTP_SSL(host, port, timeout=60, context=ssl.create_default_context()) as smtp:
            if user:
                smtp.login(user, password)
            smtp.send_message(msg)
except Exception as exc:
    # Avoid printing credentials; message only.
    print(f"EMAIL_STATUS=FAIL provider=smtp error={type(exc).__name__}", file=sys.stderr)
    sys.exit(1)
print("EMAIL_STATUS=PASS provider=smtp")
PY
  exit $?
fi

echo "EMAIL_STATUS=BLOCKED reason=no_email_provider_configured"
exit 0

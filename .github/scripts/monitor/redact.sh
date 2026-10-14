#!/bin/sh
# Redact secrets and sensitive patterns from text on stdin → stdout.
# Portable for GNU and BSD sed (GitHub Actions ubuntu + local macOS).
set -eu

sed -E \
  -e 's#(mysql|postgres|redis|amqp|https?)://[^:@/]+:[^@/]+@#\1://***:***@#gi' \
  -e 's#([Aa]uthorization:[[:space:]]*)([Bb]earer[[:space:]]+)?[^[:space:]]+#\1\2***#g' \
  -e 's#([Aa]pi[_-]?[Kk]ey[=:][[:space:]]*)[^[:space:]]+#\1***#g' \
  -e 's#(ghp_|github_pat_|gho_|ghu_|ghs_|ghr_)[A-Za-z0-9_]+#\1***#g' \
  -e 's#(sk_live_|sk_test_|re_)[A-Za-z0-9]+#\1***#g' \
  -e 's#(MYSQL_PASSWORD|REDIS_PASSWORD|RABBITMQ_PASSWORD|APP_SECRET|TENANT_DB_CREDENTIALS_KEY|PASSWORD|TOKEN|SECRET)(=|:|[[:space:]]+)[^[:space:]]+#\1\2***#gi' \
  -e 's/BEGIN [A-Z0-9 ]*PRIVATE KEY/BEGIN PRIVATE KEY ***REDACTED***/g'

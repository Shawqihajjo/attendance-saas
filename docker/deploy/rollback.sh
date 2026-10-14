#!/bin/sh
# Redeploy a previous immutable image tag without running Doctrine down migrations.
# Schema stays at the forward migration. Prefer a forward fix if the release needed schema.
#
# Required:
#   IMAGE_TAG          previous tag (for example sha-abc1234def56 or v1.2.3)
#   ENV_FILE           private env file (default .env.prod)
#   GHCR_OWNER         lowercase GitHub owner, or set ATTENDANCE_*_IMAGE explicitly
#
# Optional:
#   COMPOSE_PROJECT_NAME, HEALTHCHECK_URL, SKIP_HTTP_HEALTH
set -eu

root="$(CDPATH= cd -- "$(dirname "$0")/../.." && pwd)"
cd "$root"

if [ -z "${IMAGE_TAG:-}" ]; then
    echo "Set IMAGE_TAG to the previous immutable image tag to restore." >&2
    exit 1
fi

echo "Rolling back to IMAGE_TAG=${IMAGE_TAG}"
echo "Stopping messenger first so events are not handled by a mismatched release…"

env_file="${ENV_FILE:-.env.prod}"
project="${COMPOSE_PROJECT_NAME:-attendance}"

compose() {
    docker compose --env-file "$env_file" -f docker-compose.prod.yml -p "$project" "$@"
}

compose stop messenger 2>/dev/null || true

export DEPLOY_MODE=pull
export BACKUP_BEFORE_MIGRATE=0
# Skip migrations on rollback — schema must not move backward.
# release.sh always migrates; for rollback we pull and recreate app containers only.

# Resolve images the same way as release.sh
image_tag="$IMAGE_TAG"
owner_lc="$(printf '%s' "${GHCR_OWNER:-${GITHUB_REPOSITORY_OWNER:-}}" | tr '[:upper:]' '[:lower:]')"
if [ -z "${ATTENDANCE_PHP_IMAGE:-}" ]; then
    if [ -z "$owner_lc" ]; then
        echo "Set ATTENDANCE_PHP_IMAGE or GHCR_OWNER." >&2
        exit 1
    fi
    ATTENDANCE_PHP_IMAGE="ghcr.io/${owner_lc}/attendance-php:${image_tag}"
fi
if [ -z "${ATTENDANCE_NGINX_IMAGE:-}" ]; then
    if [ -z "$owner_lc" ]; then
        echo "Set ATTENDANCE_NGINX_IMAGE or GHCR_OWNER." >&2
        exit 1
    fi
    ATTENDANCE_NGINX_IMAGE="ghcr.io/${owner_lc}/attendance-nginx:${image_tag}"
fi
export ATTENDANCE_PHP_IMAGE ATTENDANCE_NGINX_IMAGE

compose pull php nginx messenger
compose up -d --no-build --remove-orphans php nginx messenger
compose exec -T php php bin/console app:diagnostics

if [ "${SKIP_HTTP_HEALTH:-0}" != "1" ]; then
    health_base="${HEALTHCHECK_URL:-http://127.0.0.1}"
    if command -v curl >/dev/null 2>&1; then
        curl -fsS --max-time 15 "${health_base}/api/v1/health" >/dev/null
        curl -fsS --max-time 15 "${health_base}/api/v1/health/ready" >/dev/null
    elif command -v wget >/dev/null 2>&1; then
        wget -qO- --timeout=15 "${health_base}/api/v1/health" >/dev/null
        wget -qO- --timeout=15 "${health_base}/api/v1/health/ready" >/dev/null
    fi
fi

echo "Rollback to ${IMAGE_TAG} completed. Schema was not altered."
